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

namespace IQ2i\HerseBundle\TrustedDevice;

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\NewDeviceEvent;
use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\User\UserReference;
use IQ2i\HerseBundle\User\UserResolver;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\RememberMeAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registers the device of every interactive login on a watched firewall, and warns the user when it is a new one.
 *
 * @internal
 */
final class TrustedDeviceListener
{
    public const TEMPLATE = '@IQ2iHerse/email/new_device.html.twig';
    public const SUBJECT = 'New sign-in to your account';

    /** Set on the token when the user was warned before the second factor, so that the end of the login does not warn again. */
    private const ALERTED_ATTRIBUTE = 'iq2i_herse.new_device_alerted';

    public function __construct(
        private readonly UserResolver $users,
        private readonly TrustedDeviceRepository $repository,
        private readonly DeviceCookies $cookies,
        private readonly UserAgentParser $userAgentParser,
        private readonly Mailer $mailer,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly bool $storeIp,
        private readonly bool $notifyFirstDevice,
        private readonly bool $notifyBeforeSecondFactor = false,
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $firewall = $event->getFirewallName();
        $authenticator = $event->getAuthenticator();
        if (!$this->cookies->isWatched($firewall) || !$authenticator instanceof InteractiveAuthenticatorInterface || !$authenticator->isInteractive()) {
            return;
        }

        // The password was accepted but the second factor is still to come: the device is registered once the login is complete.
        $token = $event->getAuthenticatedToken();
        $secondFactorPending = $token instanceof TwoFactorTokenInterface;
        if ($secondFactorPending && !$this->notifyBeforeSecondFactor) {
            return;
        }

        try {
            $user = $event->getUser();
            $reference = $this->users->reference($user);
            if (null === $reference) {
                return;
            }

            // A remember-me login only comes back on a browser that already went through a login: it never registers a device.
            $onlyKnown = $authenticator instanceof RememberMeAuthenticator;
            if ($token instanceof TwoFactorTokenInterface) {
                $this->warnBeforeSecondFactor($token, $user, $reference, $firewall, $event->getRequest(), $onlyKnown);

                return;
            }

            $this->register($user, $reference, $firewall, $event->getRequest(), $onlyKnown, $token->hasAttribute(self::ALERTED_ATTRIBUTE));
        } catch (\Throwable $exception) {
            // The device registry must never break a login.
            $this->logger->error('The trusted device could not be registered.', ['exception' => $exception]);
        }
    }

    private function register(object $user, UserReference $reference, string $firewall, Request $request, bool $onlyKnown, bool $alreadyAlerted): void
    {
        $now = new \DateTimeImmutable();
        $ip = $this->storeIp ? $request->getClientIp() : null;
        $expiresAt = $this->cookies->expiresAt($firewall, $now);

        $this->repository->deleteExpired($reference);

        $token = $this->cookies->token($request, $firewall);
        if (null !== $token) {
            $id = $this->repository->findActiveId($reference, $firewall, hash('sha256', $token), $now);
            if (null !== $id) {
                $this->repository->touch($id, $now, $ip, $expiresAt);
                $this->cookies->set($firewall, $token);

                return;
            }
        }

        if ($onlyKnown) {
            return;
        }

        $isFirstDevice = !$this->repository->hasAny($reference, $firewall);
        // Always a fresh token: a token chosen by the client (cookie injection from a sibling domain) would let it present
        // later a device it prepared. A browser shared by two users therefore trusts the last one to log in.
        $token = bin2hex(random_bytes(32));
        $device = new TrustedDevice($reference->type, $reference->identifier, $firewall, hash('sha256', $token), $this->userAgentParser->parse($request->headers->get('User-Agent')), $ip, $now, $expiresAt);
        $this->repository->insert($device);
        $this->cookies->set($firewall, $token);

        if (!$alreadyAlerted) {
            $this->alert($user, $reference, $firewall, $device->getLabel(), $device->getLastIp(), $device->getCreatedAt(), !$isFirstDevice || $this->notifyFirstDevice);
        }
    }

    /**
     * Warns as soon as the password is accepted from an unknown device, without registering anything.
     */
    private function warnBeforeSecondFactor(TwoFactorTokenInterface $token, object $user, UserReference $reference, string $firewall, Request $request, bool $onlyKnown): void
    {
        $cookie = $this->cookies->token($request, $firewall);
        if ($onlyKnown || (null !== $cookie && null !== $this->repository->findActiveId($reference, $firewall, hash('sha256', $cookie), new \DateTimeImmutable()))) {
            return;
        }

        $this->alert(
            $user,
            $reference,
            $firewall,
            $this->userAgentParser->parse($request->headers->get('User-Agent')),
            $this->storeIp ? $request->getClientIp() : null,
            new \DateTimeImmutable(),
            $this->repository->hasAny($reference, $firewall) || $this->notifyFirstDevice,
        );
        $token->getAuthenticatedToken()->setAttribute(self::ALERTED_ATTRIBUTE, true);
    }

    private function alert(object $user, UserReference $reference, string $firewall, ?string $label, ?string $ip, \DateTimeImmutable $at, bool $notify): void
    {
        $email = $notify ? $this->mailer->create(
            self::TEMPLATE,
            self::SUBJECT,
            $this->users->email($user),
            $this->users->locale($user),
            ['device' => $label, 'ip' => $ip, 'signed_in_at' => $at],
        ) : null;

        $event = $this->dispatcher->dispatch(new NewDeviceEvent($reference, $user, $firewall, $label, $ip, $at, $email));
        $email = $event->getEmail();
        if ($event->isCancelled() || null === $email) {
            return;
        }

        $this->mailer->send($email);
    }
}
