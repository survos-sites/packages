<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Service\PackageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\SerializerInterface;

final class PackageMetadataTest extends TestCase
{
    public function testMetadataDoesNotChangeWorkflowStateAndClearsOldCompatibility(): void
    {
        $package = new Package('acme/component');
        $package->marking = 'composer_loaded';
        $package->data = ['type' => 'library', 'require' => ['php' => '^8.3']];
        $service = new PackageService(new NullLogger(), $this->createStub(SerializerInterface::class));
        $service->populateFromComposerData($package);
        self::assertSame('composer_loaded', $package->marking);
        self::assertTrue($package->hasValidPhpVersion);
        self::assertFalse($package->isSymfonyBundle);
        self::assertFalse($package->hasValidSymfonyVersion);
        $package->data['require']['php'] = '^5.6';
        $service->populateFromComposerData($package);
        self::assertSame('composer_loaded', $package->marking);
        self::assertFalse($package->hasValidPhpVersion);
    }
    public function testSymfonyEightGateUsesAllDeclaredCoreRequirements(): void
    {
        $service = new PackageService(new NullLogger(), $this->createStub(SerializerInterface::class));
        $package = new Package('acme/bundle');
        $package->data = ['type' => 'symfony-bundle', 'require' => [
            'php' => '^8.4', 'symfony/config' => '^8.0.10',
            'symfony/http-kernel' => '^7.4', 'symfony/service-contracts' => '^3',
        ]];
        $service->populateFromComposerData($package);
        self::assertFalse($package->hasValidSymfonyVersion);
        $package->data['require']['symfony/http-kernel'] = '^7.4 || ^8.0';
        $service->populateFromComposerData($package);
        self::assertTrue($package->hasValidSymfonyVersion);
        self::assertContains('8.0', $package->symfonyVersions);
        self::assertNotContains('7.4', $package->symfonyVersions);
        self::assertSame(['8.1', '8.2'], $service->constraintComplies('>=8.1', ['8.0', '8.1', '8.2']));
    }
}
