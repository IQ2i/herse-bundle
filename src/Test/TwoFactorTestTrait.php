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

namespace IQ2i\HerseBundle\Test;

use IQ2i\HerseBundle\TwoFactor\TotpParameters;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * For the functional tests of a project whose logins ask for a second factor: gets the code, then passes the form.
 *
 * A test logs in with the password as usual, then:
 *
 *     $this->passSecondFactor($client, $this->totpCode($secret));        // TOTP
 *     $this->passSecondFactor($client, $user->getEmailAuthCode());       // email: the code is on the user, read afresh
 */
trait TwoFactorTestTrait
{
    /**
     * @param non-empty-string $secret the secret of the user
     *
     * @return string the code an authenticator app shows right now
     */
    protected function totpCode(string $secret): string
    {
        return TOTP::create($secret, TotpParameters::PERIOD, TotpParameters::ALGORITHM, TotpParameters::DIGITS)->now();
    }

    /**
     * Submits the code on the second factor form, following the redirection of the login to it when needed.
     */
    protected function passSecondFactor(KernelBrowser $client, string $code): Crawler
    {
        if ($client->getResponse()->isRedirection()) {
            $client->followRedirect();
        }

        $field = $client->getCrawler()->filter('input[name="_auth_code"]');
        if (0 === $field->count()) {
            throw new \LogicException(\sprintf('The browser is not on the second factor form, but on "%s".', $client->getRequest()->getUri()));
        }

        return $client->submit($field->ancestors()->filter('form')->first()->form(['_auth_code' => $code]));
    }
}
