<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Repository\PackageRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Discover the runtime components of curated bundles, not the whole dependency tree. */
#[\Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener(event: \Doctrine\ORM\Events::postFlush)]
final class RequiredPackageDiscovery implements \Symfony\Contracts\Service\ResetInterface
{
    private ?\Symfony\Component\Lock\LockInterface $discoveryLock = null;

    public function __construct(
        private PackageRepository $packages,
        private EntityManagerInterface $entityManager,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'lock.database.factory')]
        private \Symfony\Component\Lock\LockFactory $locks,
    ) {
    }

    public function discover(Package $bundle): void
    {
        if (!$bundle->isSymfonyBundle) {
            return;
        }

        // Different bundles can discover the same dependency. Hold this short lock
        // through the owning handler's flush, not merely through the existence check.
        if ($this->discoveryLock === null) {
            $lock = $this->locks->createLock('packages:dependency-discovery', ttl: null);
            if (!$lock->acquire()) {
                throw new \Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException(
                    'Another worker is committing discovered dependencies.', retryDelay: 100,
                );
            }
            $this->discoveryLock = $lock;
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

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->discoveryLock?->release();
        $this->discoveryLock = null;
    }
}
