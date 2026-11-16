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

namespace IQ2i\HerseBundle\Bridge\Moka\Controller;

use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The screen Moka does not have: the administrators list and revoke their own devices.
 *
 * @internal
 */
final class DeviceController
{
    public const INDEX_ROUTE = 'iq2i_herse_moka_devices';

    public function __construct(
        private readonly TrustedDevices $trustedDevices,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly string $firewall,
    ) {
    }

    public function index(): Response
    {
        $devices = [];
        foreach ($this->trustedDevices->list($this->user(), $this->firewall) as $device) {
            $devices[] = ['device' => $device, 'current' => $this->trustedDevices->isCurrent($device)];
        }

        return new Response($this->twig->render('@IQ2iHerse/moka/devices.html.twig', ['devices' => $devices]));
    }

    public function revoke(int $id, Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('iq2i_herse_revoke_device_'.$id, (string) $request->request->get('_token')))) {
            throw new AccessDeniedException('Invalid CSRF token.');
        }

        $this->trustedDevices->revoke($this->user(), $id);

        return new RedirectResponse($this->urlGenerator->generate(self::INDEX_ROUTE));
    }

    private function user(): object
    {
        return $this->tokenStorage->getToken()?->getUser() ?? throw new AccessDeniedException();
    }
}
