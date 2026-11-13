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

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Event\NewDeviceEvent;
use IQ2i\HerseBundle\Event\TwoFactorFailedEvent;
use IQ2i\HerseBundle\Event\TwoFactorSucceededEvent;
use IQ2i\HerseBundle\Tests\TestApplication\TwoFactorEntity\TwoFactorAccount;
use Psr\Cache\CacheItemPoolInterface;
use Scheb\TwoFactorBundle\SchebTwoFactorBundle;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Boots the application whose "main" firewall asks for a second factor, and plays one browser: the cookies are kept
 * between the requests. Skipped while scheb is not installed.
 */
abstract class TwoFactorKernelTestCase extends DoctrineKernelTestCase
{
    protected const FIREFOX = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:130.0) Gecko/20100101 Firefox/130.0';
    protected const SESSION_COOKIE = 'MOCKSESSID';

    /** @var array<string, string> */
    protected array $cookies = [];

    /** @var list<NewDeviceEvent> */
    protected array $deviceEvents = [];

    /** @var list<TwoFactorSucceededEvent|TwoFactorFailedEvent> */
    protected array $twoFactorEvents = [];

    /** @var list<TemplatedEmail> */
    private array $sentMails = [];

    protected static function environment(): string
    {
        return 'two_factor';
    }

    protected function setUp(): void
    {
        if (!class_exists(SchebTwoFactorBundle::class)) {
            self::markTestSkipped('scheb/2fa-bundle is not installed.');
        }

        parent::setUp();

        $this->service(CacheItemPoolInterface::class, 'test.two_factor_throttling.cache')->clear();
        $this->cookies = [];
        $this->sentMails = [];
        $this->deviceEvents = [];
        $this->twoFactorEvents = [];
        $dispatcher = $this->service(EventDispatcherInterface::class, 'event_dispatcher');
        $dispatcher->addListener(NewDeviceEvent::class, function (NewDeviceEvent $event): void {
            $this->deviceEvents[] = $event;
        });
        $dispatcher->addListener(TwoFactorSucceededEvent::class, function (TwoFactorSucceededEvent $event): void {
            $this->twoFactorEvents[] = $event;
        });
        $dispatcher->addListener(TwoFactorFailedEvent::class, function (TwoFactorFailedEvent $event): void {
            $this->twoFactorEvents[] = $event;
        });
    }

    /**
     * An account whose password is "secret".
     */
    protected function twoFactorAccount(string $email = 'alice@example.com', ?string $locale = null, bool $emailAuth = true, ?string $totpSecret = null): TwoFactorAccount
    {
        $account = new TwoFactorAccount($email, password_hash('secret', \PASSWORD_BCRYPT, ['cost' => 5]), $locale, $emailAuth, $totpSecret);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();
        $this->resetMails();

        return $account;
    }

    /**
     * The emails of the whole scenario: the mailer forgets them when the next request starts.
     *
     * @return list<TemplatedEmail>
     */
    protected function mails(): array
    {
        return $this->sentMails;
    }

    protected function resetMails(): void
    {
        parent::resetMails();
        $this->sentMails = [];
    }

    /**
     * The password step, which asks for the second factor when the account has one.
     */
    protected function signIn(string $email = 'alice@example.com', string $password = 'secret', string $ip = '203.0.113.7'): Response
    {
        return $this->send('POST', '/login', json: ['username' => $email, 'password' => $password], server: ['REMOTE_ADDR' => $ip]);
    }

    protected function submitCode(string $code, bool $trustDevice = false): Response
    {
        return $this->send('POST', '/2fa_check', ['_auth_code' => $code] + ($trustDevice ? ['_trusted' => '1'] : []));
    }

    protected function visitPrivatePage(): Response
    {
        return $this->send('GET', '/private');
    }

    /**
     * The code of the last email carrying one.
     */
    protected function emailedCode(): string
    {
        foreach (array_reverse($this->mails()) as $mail) {
            if (1 === preg_match('/sign-in code is: (\d+)|code de connexion est : (\d+)/', (string) $mail->getHtmlBody(), $matches)) {
                return $matches[2] ?? $matches[1];
            }
        }

        self::fail('An email with the code was expected.');
    }

    /**
     * The CSRF token a form of the bundle carries.
     */
    protected function csrfToken(string $html, string $field = '_csrf_token'): string
    {
        if (1 !== preg_match('/name="'.preg_quote($field, '/').'" value="([^"]+)"/', $html, $matches)) {
            self::fail('A form with a CSRF token was expected.');
        }

        return $matches[1];
    }

    /**
     * The key the enrollment screen shows next to the QR code.
     *
     * @return non-empty-string
     */
    protected function shownSecret(string $html): string
    {
        if (1 !== preg_match('#<code>([A-Z2-7 ]+)</code>#', $html, $matches)) {
            self::fail('The key of the authenticator app was expected.');
        }

        $secret = str_replace(' ', '', $matches[1]);
        \assert('' !== $secret);

        return $secret;
    }

    /**
     * The browser leaves and comes back: the device cookie stays, the session is gone.
     */
    protected function closeSession(): void
    {
        unset($this->cookies[self::SESSION_COOKIE]);
    }

    protected function assertSecondFactorAsked(Response $response): void
    {
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/2fa', (string) $response->headers->get('Location'));
    }

    protected function assertLoggedIn(): void
    {
        $response = $this->visitPrivatePage();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/private', $response->getContent());
    }

    /**
     * What the database holds, read afresh.
     *
     * @return list<TrustedDevice>
     *
     * @phpstan-impure
     */
    protected function devices(): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->getRepository(TrustedDevice::class)->findBy([], ['id' => 'ASC']);
    }

    /**
     * @param array<string, string>     $parameters
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $server
     */
    protected function send(string $method, string $path, array $parameters = [], ?array $json = null, array $server = []): Response
    {
        $server += ['HTTP_USER_AGENT' => self::FIREFOX, 'REMOTE_ADDR' => '203.0.113.7'];
        $content = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        // Caught: the access denied exceptions are what the second factor firewall turns into a redirect.
        $response = $this->kernel->handle(Request::create($path, $method, $parameters, $this->cookies, [], $server, $content), HttpKernelInterface::MAIN_REQUEST, true);
        $this->sentMails = [...$this->sentMails, ...parent::mails()];
        parent::resetMails();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared() || null === $cookie->getValue()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
