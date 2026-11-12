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

namespace IQ2i\HerseBundle\Tests;

final class TrustedDevicesQuietTest extends TrustedDevicesKernelTestCase
{
    protected static function environment(): string
    {
        return 'trusted_devices_quiet';
    }

    public function testTheFirstDeviceOfAUserIsRegisteredWithoutAnEmail(): void
    {
        $this->account();

        $this->signIn();

        self::assertCount(1, $this->devices());
        self::assertSame([], $this->mails());
        self::assertCount(1, $this->events, 'The event is dispatched even when no email is meant to be sent.');
        self::assertNull($this->events[0]->getEmail());
    }

    public function testTheNextDevicesAreWarnedAbout(): void
    {
        $this->account();
        $this->signIn();

        $this->signIn(userAgent: self::CHROME);

        self::assertCount(1, $this->mails());
        self::assertCount(2, $this->devices());
    }

    public function testTheFirstDeviceOfAFirewallIsTheFirstOfThatFirewallOnly(): void
    {
        $this->account();
        $this->signIn();

        $this->signIn(path: '/admin/login');

        self::assertSame([], $this->mails(), 'It is the first device of the user on the admin firewall.');
    }

    public function testTheIpAddressIsNotStoredWhenDisabled(): void
    {
        $this->account();
        $token = $this->token($this->signIn());
        $this->signIn(cookies: ['herse_device_main' => $token], ip: '198.51.100.9');

        self::assertNull($this->devices()[0]->getLastIp());
        self::assertNull($this->events[0]->ip);
    }

    public function testAListenerCanSupplyTheEmailOfTheFirstDevice(): void
    {
        $this->service(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, 'event_dispatcher')->addListener(\IQ2i\HerseBundle\Event\NewDeviceEvent::class, static function (\IQ2i\HerseBundle\Event\NewDeviceEvent $event): void {
            $event->setEmail((new \Symfony\Bridge\Twig\Mime\TemplatedEmail())->from('support@example.com')->to('alice@example.com')->subject('Welcome back')->text('Hello'));
        });
        $this->account();

        $this->signIn();

        self::assertSame('Welcome back', $this->mails()[0]->getSubject());
    }
}
