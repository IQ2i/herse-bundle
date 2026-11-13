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

namespace IQ2i\HerseBundle\Controller;

use IQ2i\HerseBundle\Entity\TrustedDevice;
use IQ2i\HerseBundle\Routing\HerseRouteLoader;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\TrustedDevice\TrustedDevices;
use IQ2i\HerseBundle\User\UserResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The screen where a user sees the devices their account is used from on the firewall, and revokes them.
 *
 * @internal
 */
final class TrustedDevicesController
{
    public const TEMPLATE = '@IQ2iHerse/devices/index.html.twig';
    public const CSRF_TOKEN_ID = 'iq2i_herse_devices';

    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly Security $security,
        private readonly UserResolver $users,
        private readonly TrustedDevices $devices,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function index(): Response
    {
        [$firewall, $user] = $this->context();

        return new Response($this->twig->render(self::TEMPLATE, [
            'devices' => array_map(
                fn (TrustedDevice $device): array => ['device' => $device, 'current' => $this->devices->isCurrent($device)],
                $this->devices->list($user, $firewall),
            ),
            'firewall' => $firewall,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'revokeRoute' => HerseRouteLoader::name($firewall, HerseRouteLoader::DEVICE_REVOKE),
            'revokeOthersRoute' => HerseRouteLoader::name($firewall, HerseRouteLoader::DEVICES_REVOKE_OTHERS),
        ]));
    }

    public function revoke(Request $request, int $id): Response
    {
        [$firewall, $user] = $this->context($request);

        // The device of another user, or of another firewall, is not found.
        $owned = array_filter($this->devices->list($user, $firewall), static fn (TrustedDevice $device): bool => $id === $device->getId());
        if ([] === $owned || !$this->devices->revoke($user, $id)) {
            throw new NotFoundHttpException();
        }

        return $this->done($request, $firewall, 'The device was revoked.');
    }

    public function revokeOthers(Request $request): Response
    {
        [$firewall, $user] = $this->context($request);

        $revoked = 0;
        foreach ($this->devices->list($user, $firewall) as $device) {
            if (!$this->devices->isCurrent($device) && null !== $device->getId() && $this->devices->revoke($user, $device->getId())) {
                ++$revoked;
            }
        }

        return $this->done($request, $firewall, 0 === $revoked ? 'There was no other device to revoke.' : 'The other devices were revoked.');
    }

    /**
     * @param Request|null $submitted the request changing something, whose CSRF token is checked
     *
     * @return array{string, UserInterface} the firewall and the user the devices belong to
     */
    private function context(?Request $submitted = null): array
    {
        $firewall = $this->firewalls->current() ?? throw new NotFoundHttpException();
        // A visitor is sent to the login by the firewall.
        $user = $this->security->getUser() ?? throw new AccessDeniedException();
        // The devices are the ones of the user, not of somebody impersonating them.
        if (null === $this->users->reference($user) || $this->security->isGranted('IS_IMPERSONATOR')) {
            throw new AccessDeniedHttpException();
        }

        if (null !== $submitted && !$this->csrfTokens->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $submitted->request->getString('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        return [$firewall, $user];
    }

    private function done(Request $request, string $firewall, string $message): RedirectResponse
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $this->translator?->trans($message, [], 'iq2i_herse') ?? $message);
        }

        return new RedirectResponse($this->urls->generate(HerseRouteLoader::name($firewall, HerseRouteLoader::DEVICES)));
    }
}
