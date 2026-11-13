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

namespace IQ2i\HerseBundle\Routing;

use IQ2i\HerseBundle\Controller\TotpEnrollmentController;
use IQ2i\HerseBundle\Controller\TwoFactorResetController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The routes of the screens of the bundle, one set per declared firewall, under its path prefix.
 *
 * Imported with one line: { resource: ., type: iq2i_herse }.
 */
final class HerseRouteLoader extends Loader
{
    public const TYPE = 'iq2i_herse';
    public const TWO_FACTOR_FORM = 'two_factor_form';
    public const TWO_FACTOR_CHECK = 'two_factor_check';
    public const TWO_FACTOR_SETUP = 'two_factor_setup';
    public const TWO_FACTOR_RESET = 'two_factor_reset';

    // The setup is not under "/2fa": the access rule opening that path to a pending second factor must not cover it.
    private const PATHS = [
        self::TWO_FACTOR_FORM => '/2fa',
        self::TWO_FACTOR_CHECK => '/2fa_check',
        self::TWO_FACTOR_SETUP => '/second-factor/setup',
        self::TWO_FACTOR_RESET => '/second-factor/reset/{type}/{id}',
    ];

    /**
     * @param array<string, array{path_prefix: string, two_factor: bool}> $firewalls       the declared firewalls
     * @param 'email'|'totp'|null                                         $twoFactorMethod null while the second factor is disabled
     * @param bool                                                        $twoFactorReset  whether an administrator can reset the second factor of a user
     */
    public function __construct(
        private readonly array $firewalls,
        private readonly ?string $twoFactorMethod,
        private readonly bool $twoFactorReset = false,
    ) {
        parent::__construct();
    }

    public static function name(string $firewall, string $action): string
    {
        return 'iq2i_herse_'.$firewall.'_'.$action;
    }

    public static function path(string $pathPrefix, string $action): string
    {
        return $pathPrefix.self::PATHS[$action];
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ($this->firewalls as $name => $firewall) {
            if (null === $this->twoFactorMethod || !$firewall['two_factor']) {
                continue;
            }

            $routes->add(self::name($name, self::TWO_FACTOR_FORM), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_FORM), ['_controller' => 'scheb_two_factor.form_controller::form'], methods: ['GET']));
            // No controller: the firewall answers the submitted code.
            $routes->add(self::name($name, self::TWO_FACTOR_CHECK), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_CHECK), methods: ['POST']));

            if ('totp' === $this->twoFactorMethod) {
                $routes->add(self::name($name, self::TWO_FACTOR_SETUP), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_SETUP), ['_controller' => TotpEnrollmentController::class], methods: ['GET', 'POST']));
            }

            if ('totp' === $this->twoFactorMethod && $this->twoFactorReset) {
                $routes->add(self::name($name, self::TWO_FACTOR_RESET), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_RESET), ['_controller' => TwoFactorResetController::class], methods: ['GET', 'POST']));
            }
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
