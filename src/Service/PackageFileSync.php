<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Entity\PackageFile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a package's PackageFile rows equal to the documents it currently holds. An
 * unchanged document keeps its row (and its vector); a changed one is replaced by a new
 * row, which starts at the workflow's initial place; a vanished one is removed.
 * Persisting only: the caller's flush stores the rows and starts their workflow.
 */
final class PackageFileSync
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, string> $paths the name each document was found under, by kind */
    public function sync(Package $package, array $paths = []): void
    {
        $documents = [PackageFile::README => $package->readme, PackageFile::AGENTS => $package->agentsMd];
        $stored = [];
        foreach ($this->em->getRepository(PackageFile::class)->findBy(['package' => $package]) as $file) {
            $stored[$file->kind][] = $file;
        }
        foreach ($documents as $kind => $content) {
            $present = trim($content ?? '') !== '';
            $sha = $present ? hash('sha256', $content) : null;
            $kept = false;
            foreach ($stored[$kind] ?? [] as $file) {
                if ($sha !== null && $file->sha === $sha && !$kept) {
                    $kept = true;
                } else {
                    $this->em->remove($file);
                }
            }
            if ($present && !$kept) {
                $this->em->persist(new PackageFile($package, $kind, $paths[$kind] ?? null, $content));
            }
        }
    }
}
