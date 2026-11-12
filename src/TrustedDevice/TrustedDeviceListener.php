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
    ) {
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $firewall = $event->getFirewallName();
        $authenticator = $event->getAuthenticator();
        if (!$this->cookies->isWatched($firewall) || !$authenticator instanceof InteractiveAuthenticatorInterface || !$authenticator->isInteractive()) {
            return;
        }

        try {
            $user = $event->getUser();
            $reference = $this->users->reference($user);
            if (null !== $reference) {
                // A remember-me login only comes back on a browser that already went through a login: it never registers a device.
                $this->register($user, $reference, $firewall, $event->getRequest(), $authenticator instanceof RememberMeAuthenticator);
            }
        } catch (\Throwable $exception) {
            // The device registry must never break a login.
            $this->logger->error('The trusted device could not be registered.', ['exception' => $exception]);
        }
    }

    private function register(object $user, UserReference $reference, string $firewall, Request $request, bool $onlyKnown): void
    {
        $now = new \DateTimeImmutable();
        $ip = $this->storeIp ? $request->getClientIp() : null;
        $expiresAt = $this->cookies->expiresAt($firewall, $now);

        $this->repository->deleteExpired($reference);

        $token = $this->cookies->token($request, $firewall);
        if (null !== $token) {
            $device = $this->repository->findOne($reference, $firewall, hash('sha256', $token));
            if (null !== $device) {
                $device->markUsed($now, $ip, $expiresAt);
                $this->repository->save();
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
        $this->repository->persist($device);
        $this->cookies->set($firewall, $token);

        $this->alert($user, $reference, $device, !$isFirstDevice || $this->notifyFirstDevice);
    }

    private function alert(object $user, UserReference $reference, TrustedDevice $device, bool $notify): void
    {
        $email = $notify ? $this->mailer->create(
            self::TEMPLATE,
            self::SUBJECT,
            $this->users->email($user),
            $this->users->locale($user),
            ['device' => $device->getLabel(), 'ip' => $device->getLastIp(), 'signed_in_at' => $device->getCreatedAt()],
        ) : null;

        $event = $this->dispatcher->dispatch(new NewDeviceEvent($reference, $user, $device->getFirewall(), $device->getLabel(), $device->getLastIp(), $device->getCreatedAt(), $email));
        $email = $event->getEmail();
        if ($event->isCancelled() || null === $email) {
            return;
        }

        $this->mailer->send($email);
    }
}
