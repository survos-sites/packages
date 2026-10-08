<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PackagistChangesRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:packagist:changes', 'Queue load for tracked packages that Packagist reports as changed since the last run')]
final class PackagistChangesCommand
{
    public function __construct(
        private PackagistChangesRefresher $refresher,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $changes = $this->refresher->refresh();
        if ($changes->initialized) {
            $io->success('Cursor initialized to now. The next run reports changes from this point on.');

            return Command::SUCCESS;
        }
        if ($changes->resync) {
            $io->warning('Packagist could not replay from the stored cursor; changes in the gap were missed. Reload affected packages with state:iterate.');
        }
        $io->definitionList(
            ['Packages changed on Packagist' => $changes->changed],
            ['Tracked, load queued' => count($changes->queued)],
            ['Tracked, mid-workflow (skipped)' => count($changes->skipped)],
            ['Deleted on Packagist' => count($changes->deleted)],
        );
        if ($io->isVerbose() && $changes->queued) {
            $io->listing($changes->queued);
        }

        return Command::SUCCESS;
    }
}
