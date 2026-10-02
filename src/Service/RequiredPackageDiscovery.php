<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Discover the runtime components of curated bundles, not the whole dependency tree. */
final class RequiredPackageDiscovery
{
    public function __construct(
        private PackageRepository $packages,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function discover(Package $bundle): void
    {
        if (!$bundle->isSymfonyBundle) {
            return;
        }

        $pending = [];
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Package) {
                $pending[$entity->name] = true;
            }
        }
        foreach (array_keys($bundle->data['require'] ?? []) as $name) {
            // Composer platform requirements have no vendor/name pair.
            if (!is_string($name) || !preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $name)) {
                continue;
            }
            if ($name === $bundle->name || isset($pending[$name]) || $this->packages->findOneBy(['name' => $name])) {
                continue;
            }
            $this->entityManager->persist(new Package($name));
            $pending[$name] = true;
        }
        // The workflow handler owns the flush. Initial-place kickoff owns dispatch.
    }
}
