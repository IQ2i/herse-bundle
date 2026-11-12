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

namespace App\Entity\Order;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\OrderItemUnit as BaseOrderItemUnit;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_order_item_unit')]
class OrderItemUnit extends BaseOrderItemUnit
{
}
