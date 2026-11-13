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

namespace IQ2i\HerseBundle\Tests\Twig;

use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber;
use IQ2i\HerseBundle\Twig\HerseExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class HerseExtensionTest extends TestCase
{
    public function testThePasswordPolicyIsListedInATemplate(): void
    {
        $twig = new Environment(new ArrayLoader(['help' => '{% for rule in herse_password_policy() %}[{{ rule }}]{% endfor %}']));
        $twig->addExtension(new HerseExtension(new PasswordPolicyDescriber(10, 0)));

        self::assertSame('[at least 10 characters]', $twig->render('help'));
    }
}
