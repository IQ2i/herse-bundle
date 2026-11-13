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

use IQ2i\HerseBundle\Event\TwoFactorEnabledEvent;
use IQ2i\HerseBundle\Security\FirewallRegistry;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\TwoFactor\PendingTotp;
use IQ2i\HerseBundle\TwoFactor\QrCode\QrCodeRendererInterface;
use IQ2i\HerseBundle\TwoFactor\SecondFactorThrottler;
use IQ2i\HerseBundle\User\UserResolver;
use Scheb\TwoFactorBundle\Model\PersisterInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\TrustedDeviceManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * The screen where a user enrolls an authenticator app: a QR code, then a first code proving the app has the secret.
 *
 * The secret stays in the session until that proof: it is only stored on the account afterwards.
 *
 * @internal
 */
final class TotpEnrollmentController
{
    public const TEMPLATE = '@IQ2iHerse/two_factor/setup.html.twig';
    public const CSRF_TOKEN_ID = 'iq2i_herse_two_factor_setup';

    private const SESSION_KEY = 'iq2i_herse.totp_enrollment.';

    public function __construct(
        private readonly FirewallRegistry $firewalls,
        private readonly Security $security,
        private readonly TotpAuthenticatorInterface $authenticator,
        private readonly PersisterInterface $persister,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly Environment $twig,
        private readonly LogoutUrlGenerator $logoutUrls,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly UserResolver $users,
        private readonly ?SecondFactorThrottler $throttler,
        private readonly ?QrCodeRendererInterface $qrCodes,
        private readonly ?TrustedDeviceManagerInterface $trustedDevices,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $firewall = $this->firewalls->current() ?? throw new NotFoundHttpException();
        // A visitor is sent to the login by the firewall.
        $user = $this->security->getUser() ?? throw new AccessDeniedException();
        // Somebody impersonating a user must never enroll their own device on the account of that user.
        if (!$user instanceof TotpTwoFactorInterface || $this->security->isGranted('IS_IMPERSONATOR')) {
            throw new AccessDeniedHttpException();
        }

        if ($user->isTotpAuthenticationEnabled()) {
            return new RedirectResponse($this->firewalls->home($firewall));
        }

        $session = $request->getSession();
        $secret = $session->get(self::SESSION_KEY.$firewall);
        if (!\is_string($secret) || '' === $secret) {
            $secret = $this->authenticator->generateSecret();
            $session->set(self::SESSION_KEY.$firewall, $secret);
        }

        $pending = new PendingTotp($user->getTotpAuthenticationUsername() ?? $user->getUserIdentifier(), $secret);
        $canTrustDevice = $this->trustedDevices?->canSetTrustedDevice($user, $request, $firewall) ?? false;
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->csrfTokens->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }

            $error = $this->verify($request, $firewall, $user, $user->getUserIdentifier(), $pending);
            if (null === $error) {
                $user->setTotpSecret($secret);
                $this->persister->persist($user);
                $session->remove(self::SESSION_KEY.$firewall);
                if (null !== $this->trustedDevices && $canTrustDevice && $request->request->getBoolean('_trusted')) {
                    $this->trustedDevices->addTrustedDevice($user, $firewall);
                }
                $this->dispatcher->dispatch(new TwoFactorEnabledEvent($this->users->reference($user), $user, $firewall, $request->getClientIp()));

                return new RedirectResponse($this->firewalls->home($firewall));
            }
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'qrCode' => $this->qrCodes?->render($this->authenticator->getQRContent($pending)),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error' => $error,
            'canTrustDevice' => $canTrustDevice,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'logoutPath' => $this->logoutUrls->getLogoutPath(),
        ]), null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return 'invalid'|'throttled'|null what is wrong with the submitted code
     */
    private function verify(Request $request, string $firewall, TotpTwoFactorInterface $user, string $identifier, PendingTotp $pending): ?string
    {
        // The identifier of the login: the wrong codes of the two screens share the same attempts.
        try {
            $this->throttler?->assertAllowed($firewall, $identifier);
        } catch (TooManyLoginAttemptsAuthenticationException) {
            return 'throttled';
        }

        // The spaces an authenticator app shows between the digits are not part of the code.
        $code = preg_replace('/\s+/', '', $request->request->getString('_auth_code')) ?? '';
        if ('' === $code || !$this->authenticator->checkCode($pending, $code)) {
            $this->throttler?->recordFailure($firewall, $identifier, $user, $request->getClientIp());

            return 'invalid';
        }

        $this->throttler?->reset($firewall, $identifier);

        return null;
    }
}
