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

namespace IQ2i\HerseBundle\TwoFactor;

use IQ2i\HerseBundle\Mailer\Mailer;
use IQ2i\HerseBundle\User\UserResolver;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;

/**
 * Sends the code of the email second factor with the email service of the bundle, in place of the mailer of scheb.
 *
 * @internal
 */
final class AuthCodeMailer implements AuthCodeMailerInterface
{
    public const TEMPLATE = '@IQ2iHerse/email/two_factor_code.html.twig';
    public const SUBJECT = 'Your sign-in code';

    public function __construct(
        private readonly UserResolver $users,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendAuthCode(TwoFactorInterface $user): void
    {
        $code = $user->getEmailAuthCode();
        if (null === $code || '' === $code) {
            $this->logger->warning('No two-factor code is available for the user, the email is not sent.');

            return;
        }

        $recipient = $user->getEmailAuthRecipient();
        $email = $this->mailer->create(self::TEMPLATE, self::SUBJECT, '' === $recipient ? null : $recipient, $this->users->locale($user), ['code' => $code]);
        if (null !== $email) {
            $this->mailer->send($email);
        }
    }
}
