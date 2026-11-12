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

use IQ2i\HerseBundle\TrustedDevice\TrustedDeviceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The devices of a user that logs in again are cleaned on the fly: this bounds the retention (the IP is personal data)
 * of the users that never come back.
 *
 * @internal
 */
#[AsCommand(name: 'iq2i:herse:purge-trusted-devices', description: 'Deletes the expired trusted devices')]
final class PurgeTrustedDevicesCommand extends Command
{
    public function __construct(private readonly TrustedDeviceRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Counts the expired devices without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $io->success(\sprintf('[Dry run] %d expired trusted device(s) would be deleted.', $this->repository->countExpired()));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d expired trusted device(s) deleted.', $this->repository->deleteExpired()));

        return Command::SUCCESS;
    }
}
