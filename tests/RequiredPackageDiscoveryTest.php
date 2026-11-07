<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Service\RequiredPackageDiscovery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

final class RequiredPackageDiscoveryTest extends TestCase
{
    public function testOnlyMissingRuntimeComponentsArePersistedOnce(): void
    {
        $bundle = new Package('acme/bundle');
        $bundle->data = ['type' => 'symfony-bundle', 'require' => [
            'php' => '^8.3', 'ext-json' => '*', 'lib-icu' => '*', 'composer-runtime-api' => '^2',
            'acme/bundle' => '*', 'acme/existing' => '^1', 'acme/pending' => '^1', 'acme/component' => '^2',
        ], 'require-dev' => ['acme/dev-only' => '^1']];
        $pending = [new Package('acme/pending')];
        $repository = $this->createStub(PackageRepository::class);
        $repository->method('findOneBy')->willReturnCallback(fn (array $criteria) => $criteria['name'] === 'acme/existing' ? new Package('acme/existing') : null);
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturnCallback(function () use (&$pending) { return $pending; });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects(self::once())->method('persist')->willReturnCallback(function (Package $package) use (&$pending) {
            self::assertSame('acme/component', $package->name);
            $pending[] = $package;
        });
        $em->expects(self::never())->method('flush');
        $discovery = new RequiredPackageDiscovery($repository, $em, new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()));
        $discovery->discover($bundle);
        $discovery->discover($bundle);
    }

    public function testDiscoveryLockIsHeldUntilFlushAndReleasedOnReset(): void
    {
        $locks = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore());
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));
        $discovery = new RequiredPackageDiscovery($this->createStub(PackageRepository::class), $em, $locks);
        $bundle = new Package('acme/bundle');
        $bundle->data = ['type' => 'symfony-bundle', 'require' => []];
        $otherWorker = $locks->createLock('packages:dependency-discovery', ttl: null);

        $discovery->discover($bundle);
        self::assertFalse($otherWorker->acquire());
        $discovery->postFlush();
        self::assertTrue($otherWorker->acquire());
        $otherWorker->release();
        $discovery->discover($bundle);
        self::assertFalse($otherWorker->acquire());
        $discovery->reset();
        self::assertTrue($otherWorker->acquire());
        $otherWorker->release();
    }

    public function testLibrariesDoNotExpandTheCatalogueRecursively(): void
    {
        $library = new Package('acme/component');
        $library->data = ['type' => 'library', 'require' => ['another/package' => '^1']];
        $repository = $this->createMock(PackageRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        (new RequiredPackageDiscovery($repository, $em, new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore())))->discover($library);
    }
}
