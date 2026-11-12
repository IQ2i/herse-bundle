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

namespace App\Entity\Taxation;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Taxation\Model\TaxCategory as BaseTaxCategory;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_tax_category')]
class TaxCategory extends BaseTaxCategory
{
}
