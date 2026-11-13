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

    private const PATHS = [
        self::TWO_FACTOR_FORM => '/2fa',
        self::TWO_FACTOR_CHECK => '/2fa_check',
    ];

    /**
     * @param array<string, array{path_prefix: string, two_factor: bool}> $firewalls the declared firewalls
     */
    public function __construct(
        private readonly array $firewalls,
        private readonly bool $twoFactor,
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
            if (!$this->twoFactor || !$firewall['two_factor']) {
                continue;
            }

            $routes->add(self::name($name, self::TWO_FACTOR_FORM), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_FORM), ['_controller' => 'scheb_two_factor.form_controller::form'], methods: ['GET']));
            // No controller: the firewall answers the submitted code.
            $routes->add(self::name($name, self::TWO_FACTOR_CHECK), new Route(self::path($firewall['path_prefix'], self::TWO_FACTOR_CHECK), methods: ['POST']));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
