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

namespace App\Entity\Shipping;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Shipping\Model\ShippingCategory as BaseShippingCategory;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_shipping_category')]
class ShippingCategory extends BaseShippingCategory
{
}
