<?php

namespace App\Command;

use Castor\Attribute\AsSymfonyTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:hello', 'Says hello from a Symfony application')]
#[AsSymfonyTask(name: 'symfony:hello', originalName: 'app:hello')]
class HelloCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello');

        return Command::SUCCESS;
    }
}
