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

namespace IQ2i\HerseBundle\Tests\TestApplication;

use Doctrine\ORM\EntityManagerInterface;
use IQ2i\HerseBundle\Tests\TestApplication\Entity\Account;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets a new password, then logs the user in: the password write comes before the authentication.
 */
final class ResetPasswordController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->entityManager->getRepository(Account::class)->findOneBy(['email' => $request->request->getString('email')]);
        \assert($user instanceof Account);

        $user->setPassword($this->hasher->hashPassword($user, $request->request->getString('password')));
        $this->entityManager->flush();

        $this->security->login($user, null, 'main');

        return new Response();
    }
}
