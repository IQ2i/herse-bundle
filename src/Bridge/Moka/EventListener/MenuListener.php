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

namespace IQ2i\HerseBundle\Bridge\Moka\EventListener;

use IQ2i\HerseBundle\Bridge\Moka\Controller\DeviceController;
use Moka\Cms\Admin\Event\ConfigureMenuEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds the device screen to the menu of the Moka administration.
 *
 * @internal
 */
final class MenuListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ConfigureMenuEvent $event): void
    {
        // Moka translates the labels in its own domain: the label is translated here.
        $event->getMenu()->addChild('iq2i_herse_devices', [
            'label' => $this->translator->trans('My devices', [], 'iq2i_herse'),
            'route' => DeviceController::INDEX_ROUTE,
        ]);
    }
}
