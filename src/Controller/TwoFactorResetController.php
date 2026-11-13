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

use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetter;
use IQ2i\HerseBundle\User\UserLoader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The screen where an administrator removes the authenticator app of a user who lost it, after a confirmation.
 *
 * @internal
 */
final class TwoFactorResetController
{
    public const TEMPLATE = '@IQ2iHerse/two_factor/reset.html.twig';
    public const CSRF_TOKEN_ID = 'iq2i_herse_two_factor_reset';

    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly Security $security,
        private readonly UserLoader $users,
        private readonly TwoFactorResetter $resetter,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly Environment $twig,
        private readonly string $role,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function __invoke(Request $request, string $type, string $id): Response
    {
        $firewall = $this->firewalls->current() ?? throw new NotFoundHttpException();
        // A visitor is sent to the login by the firewall.
        $admin = $this->security->getUser() ?? throw new AccessDeniedException();
        if (!$this->security->isGranted($this->role) || $this->security->isGranted('IS_IMPERSONATOR')) {
            throw new AccessDeniedHttpException();
        }

        $user = $this->users->find($type, $id);
        if (!$user instanceof TotpTwoFactorInterface) {
            throw new NotFoundHttpException();
        }

        $back = $this->localPath($request->request->get('back') ?? $request->query->get('back')) ?? $this->firewalls->home($firewall);

        if ($request->isMethod('POST')) {
            if (!$this->csrfTokens->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }

            $this->resetter->reset($user, $admin);

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $message = 'The two-factor authentication was reset: the user sets it up again at the next login.';
                $session->getFlashBag()->add('success', $this->translator?->trans($message, [], 'iq2i_herse') ?? $message);
            }

            return new RedirectResponse($back);
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'user' => $user,
            'username' => $user->getTotpAuthenticationUsername(),
            'enrolled' => $user->isTotpAuthenticationEnabled(),
            'back' => $back,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]));
    }

    /**
     * Where to go back to is given by the page linking here: only a path of this site is followed.
     */
    private function localPath(mixed $path): ?string
    {
        return \is_string($path) && 1 === preg_match('#^/(?![/\\\\])[^\\\\\x00-\x1f]*$#', $path) ? $path : null;
    }
}
