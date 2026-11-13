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

namespace IQ2i\HerseBundle\Command;

use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\TwoFactor\TwoFactorResetter;
use IQ2i\HerseBundle\User\UserLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The last resort when nobody can reset the second factor from the application, e.g. the only administrator lost their phone.
 *
 * @internal
 */
#[AsCommand(name: 'iq2i:herse:reset-two-factor', description: 'Removes the authenticator app of a user, who enrolls a new one at the next login')]
final class ResetTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserLoader $users,
        private readonly TwoFactorResetter $resetter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The type of the user (iq2i_herse.users.<class>.type), when several types have the address')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string|null $type */
        $type = $input->getOption('type');

        $users = $this->users->findByEmail($email, $type);
        if ([] === $users) {
            $io->error(\sprintf('No user has the email address "%s".', $email));

            return Command::FAILURE;
        }

        if (\count($users) > 1) {
            $io->error(\sprintf('Several types of users have the email address "%s": choose one with --type (%s).', $email, implode(', ', array_keys($users))));

            return Command::FAILURE;
        }

        $user = reset($users);
        if (!$user instanceof TotpTwoFactorInterface) {
            $io->error(\sprintf('The class "%s" does not implement "%s".', $user::class, TotpTwoFactorInterface::class));

            return Command::FAILURE;
        }

        $this->resetter->reset($user);
        $io->success(\sprintf('The second factor of "%s" (%s) was reset.', $email, array_key_first($users)));

        return Command::SUCCESS;
    }
}
