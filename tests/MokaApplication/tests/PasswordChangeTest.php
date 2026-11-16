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

namespace App\Tests;

use IQ2i\HerseBundle\Event\PasswordChangedEvent;
use IQ2i\HerseBundle\PasswordChange\PasswordChangeContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Moka sets a password in one place, its reset screen, also used for the first password of a new account.
 */
final class PasswordChangeTest extends MokaKernelTestCase
{
    private const NEW_PASSWORD = 'An0ther-secret!';

    /** @var list<PasswordChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = [];
        $this->service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PasswordChangedEvent::class, function (PasswordChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testAnAdministratorWhoResetsTheirPasswordIsNotified(): void
    {
        $admin = $this->createAdmin();

        $this->assertRedirectedTo('/admin/login', $this->resetPassword($admin, self::NEW_PASSWORD));

        self::assertSame(['Your password has been changed'], $this->mailSubjects());
        self::assertSame('admin@example.com', $this->mails()[0]->getTo()[0]->getAddress());
        self::assertCount(1, $this->events);
        self::assertSame(PasswordChangeContext::Self, $this->events[0]->context);
        self::assertSame('admin', $this->events[0]->reference->type);
        $this->assertRedirectedTo('/admin/', $this->login(password: self::NEW_PASSWORD));
    }

    public function testTheFirstPasswordOfANewAccountSendsNothing(): void
    {
        $admin = $this->createAdmin(password: null);

        $this->assertRedirectedTo('/admin/login', $this->resetPassword($admin, self::NEW_PASSWORD));

        self::assertSame([], $this->mails());
        self::assertSame([], $this->events);
    }

    public function testALoginSendsNoPasswordNotification(): void
    {
        $this->createAdmin();

        $this->assertRedirectedTo('/admin/', $this->login());

        self::assertSame([], $this->events);
        self::assertNotContains('Your password has been changed', $this->mailSubjects());
    }
}
