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

namespace App\Entity\Customer;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Customer\Model\CustomerGroup as BaseCustomerGroup;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_customer_group')]
class CustomerGroup extends BaseCustomerGroup
{
}
