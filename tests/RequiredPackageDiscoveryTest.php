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
            self::assertSame('new', $package->getMarking());
            $pending[] = $package;
        });
        $em->expects(self::never())->method('flush');
        $discovery = new RequiredPackageDiscovery($repository, $em);
        $discovery->discover($bundle);
        $discovery->discover($bundle);
    }

    public function testLibrariesDoNotExpandTheCatalogueRecursively(): void
    {
        $library = new Package('acme/component');
        $library->data = ['type' => 'library', 'require' => ['another/package' => '^1']];
        $repository = $this->createMock(PackageRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        (new RequiredPackageDiscovery($repository, $em))->discover($library);
    }
}
