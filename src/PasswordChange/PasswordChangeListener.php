<?php

declare(strict_types=1);

/*
 * This file is part of the Herse Bundle.
 *
 * (c) Loïc Sapone <loic@sapone.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IQ2i\HerseBundle\PasswordChange;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\User\UserReference;
use IQ2i\HerseBundle\User\UserResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Detects the password changes at the Doctrine level, so that no entry point of the project can be forgotten.
 *
 * The changes are collected while flushing and handled once the flush has succeeded.
 *
 * @internal
 */
final class PasswordChangeListener
{
    public const TEMPLATE = '@IQ2iHerse/email/password_changed.html.twig';
    public const SUBJECT = 'Your password has been changed';

    /** @var list<array{user: object, reference: UserReference, changedAt: \DateTimeImmutable}> */
    private array $pending = [];

    /** @var array<class-string, true> */
    private array $checkedClasses = [];

    public function __construct(
        private readonly UserResolver $users,
        private readonly AuthenticationWindow $authenticationWindow,
        private readonly PasswordChangeNotification $notification,
        private readonly Mailer $mailer,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly bool $notifyOnOtherUser,
        private readonly bool $notifyOnConsole,
        private readonly ?TrustedDevices $trustedDevices = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // A previous flush that failed after this point must never leave a notification behind.
        $this->pending = [];

        if ($this->authenticationWindow->isOpen() || !$this->notification->isEnabled()) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $user) {
            $property = $this->users->passwordProperty($user);
            if (null === $property) {
                continue;
            }

            $this->assertMapped($entityManager, $user, $property);

            $change = $unitOfWork->getEntityChangeSet($user)[$property] ?? null;
            if (!\is_array($change)) {
                continue;
            }

            [$old, $new] = $change;
            // No previous password: the first one of an account. No new password: the account loses it, nobody is warned.
            if (!\is_string($old) || '' === $old || !\is_string($new) || '' === $new) {
                continue;
            }

            $reference = $this->users->reference($user);
            \assert(null !== $reference);
            $this->pending[] = ['user' => $user, 'reference' => $reference, 'changedAt' => new \DateTimeImmutable()];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $change) {
            try {
                $context = $this->context($change['reference']);
                $this->revokeDevices($change['user'], $context);
                $this->notify($change['user'], $change['reference'], $change['changedAt'], $context);
            } catch (\Throwable $exception) {
                $this->logger->error('The password change notification failed.', ['exception' => $exception]);
            }
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $this->pending = [];
    }

    /**
     * The devices lose their trust whatever the notification settings are. The owner keeps the browser they are using.
     */
    private function revokeDevices(object $user, PasswordChangeContext $context): void
    {
        if (null === $this->trustedDevices) {
            return;
        }

        try {
            $this->trustedDevices->revokeAll($user, PasswordChangeContext::Self === $context);
        } catch (\Throwable $exception) {
            $this->logger->error('The trusted devices could not be revoked after the password change.', ['exception' => $exception]);
        }
    }

    private function notify(object $user, UserReference $reference, \DateTimeImmutable $changedAt, PasswordChangeContext $context): void
    {
        if (PasswordChangeContext::OtherUser === $context && !$this->notifyOnOtherUser) {
            return;
        }
        if (PasswordChangeContext::Console === $context && !$this->notifyOnConsole) {
            return;
        }

        $email = $this->mailer->create(self::TEMPLATE, self::SUBJECT, $this->users->email($user), $this->users->locale($user), ['changed_at' => $changedAt, 'context' => $context]);
        if (null === $email) {
            return;
        }

        $event = $this->dispatcher->dispatch(new PasswordChangedEvent($reference, $user, $context, $changedAt, $email));
        if ($event->isCancelled()) {
            return;
        }

        $this->mailer->send($event->getEmail());
    }

    private function context(UserReference $reference): PasswordChangeContext
    {
        if (null === $this->requestStack->getMainRequest()) {
            return PasswordChangeContext::Console;
        }

        $authenticated = $this->tokenStorage->getToken()?->getUser();
        if (null === $authenticated) {
            return PasswordChangeContext::Self;
        }

        $current = $this->users->reference($authenticated);

        return null !== $current && $current->type === $reference->type && $current->identifier === $reference->identifier
            ? PasswordChangeContext::Self
            : PasswordChangeContext::OtherUser;
    }

    private function assertMapped(EntityManagerInterface $entityManager, object $user, string $property): void
    {
        if (isset($this->checkedClasses[$user::class])) {
            return;
        }

        if (!$entityManager->getClassMetadata($user::class)->hasField($property)) {
            throw new \LogicException(\sprintf('iq2i_herse.users: "%s" is not a field mapped by Doctrine on "%s", the password changes cannot be detected.', $property, $user::class));
        }

        $this->checkedClasses[$user::class] = true;
    }
}
