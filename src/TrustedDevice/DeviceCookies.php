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

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the device cookie of a request and writes the one of its response.
 *
 * The security events fire long before the response exists, so the decisions are kept here until the response event.
 *
 * @internal
 */
final class DeviceCookies implements ResetInterface
{
    /** @var array<string, string> token to set, by firewall */
    private array $toSet = [];

    /** @var array<string, true> */
    private array $toClear = [];

    /**
     * @param array<string, array{cookie_name: string, lifetime: string}> $firewalls
     */
    public function __construct(private readonly array $firewalls)
    {
    }

    public function isWatched(string $firewall): bool
    {
        return isset($this->firewalls[$firewall]);
    }

    /**
     * The token of the request, null when it has none or when it is not one the bundle could have issued.
     */
    public function token(Request $request, string $firewall): ?string
    {
        if (!isset($this->firewalls[$firewall])) {
            return null;
        }

        $token = $request->cookies->get($this->firewalls[$firewall]['cookie_name']);

        return \is_string($token) && 1 === preg_match('/^[0-9a-f]{64}$/', $token) ? $token : null;
    }

    /**
     * The token of the browser making the request: the one about to be set when its device was just registered, the cookie otherwise.
     */
    public function current(Request $request, string $firewall): ?string
    {
        return $this->toSet[$firewall] ?? $this->token($request, $firewall);
    }

    public function expiresAt(string $firewall, \DateTimeImmutable $from): \DateTimeImmutable
    {
        return $from->modify('+'.$this->firewalls[$firewall]['lifetime']);
    }

    public function set(string $firewall, string $token): void
    {
        unset($this->toClear[$firewall]);
        $this->toSet[$firewall] = $token;
    }

    public function clear(string $firewall): void
    {
        unset($this->toSet[$firewall]);
        $this->toClear[$firewall] = true;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        foreach ($this->toClear as $firewall => $_) {
            $this->clearOn($response, $firewall);
        }
        foreach ($this->toSet as $firewall => $token) {
            $this->setOn($response, $firewall, $token);
        }
        $this->reset();
    }

    public function reset(): void
    {
        $this->toSet = [];
        $this->toClear = [];
    }

    private function setOn(Response $response, string $firewall, string $token): void
    {
        $response->headers->setCookie(Cookie::create(
            name: $this->firewalls[$firewall]['cookie_name'],
            value: $token,
            expire: $this->expiresAt($firewall, new \DateTimeImmutable()),
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    private function clearOn(Response $response, string $firewall): void
    {
        $response->headers->clearCookie($this->firewalls[$firewall]['cookie_name'], '/', null, true, true, Cookie::SAMESITE_LAX);
    }
}
