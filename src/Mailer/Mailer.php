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

namespace IQ2i\HerseBundle\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The single way out of the bundle emails. Failures are logged, never thrown: an email must not break a login or a password change.
 *
 * @internal
 */
final class Mailer
{
    public const TRANSLATION_DOMAIN = 'iq2i_herse';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly string $sender,
        private readonly ?string $senderName,
        private readonly ?string $appName,
    ) {
    }

    /**
     * @param string               $subject translation key of the subject, in the iq2i_herse domain
     * @param array<string, mixed> $context
     */
    public function create(string $template, string $subject, ?string $recipient, ?string $locale, array $context = []): ?TemplatedEmail
    {
        if (null === $recipient) {
            $this->logger->warning('No email address is available for the recipient, the "{subject}" email is not sent.', ['subject' => $subject]);

            return null;
        }

        try {
            $sender = new Address($this->sender, $this->senderName ?? '');
        } catch (MimeException $exception) {
            $this->logger->error('iq2i_herse.mailer.sender is not a valid email address, the "{subject}" email is not sent.', ['subject' => $subject, 'exception' => $exception]);

            return null;
        }

        try {
            $email = (new TemplatedEmail())
                ->from($sender)
                ->to($recipient)
                ->subject($this->translator->trans($subject, ['%app_name%' => $this->appName ?? ''], self::TRANSLATION_DOMAIN, $locale))
                ->htmlTemplate($template)
                ->locale($locale)
                ->context($context + ['app_name' => $this->appName, 'sender' => $this->sender, 'locale' => $locale])
            ;
        } catch (MimeException $exception) {
            $this->logger->warning('The recipient email address is invalid, the "{subject}" email is not sent.', ['subject' => $subject, 'exception' => $exception]);

            return null;
        }

        return $email;
    }

    public function send(TemplatedEmail $email): bool
    {
        try {
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            $this->logger->error('The security email could not be sent.', ['exception' => $exception]);

            return false;
        }

        return true;
    }
}
