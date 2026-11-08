<?php

namespace App\Tests\Workflow;

use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Service\PackageService;
use App\Workflow\PackageWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Packagist\Api\Client;
use Packagist\Api\Result\Package as Metadata;
use Packagist\Api\Result\Package\Version;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class PackageWorkflowTest extends TestCase
{
    public function testDefaultBranchMetadataIsUsedEvenWhenAnotherVersionIsLast(): void
    {
        $main = new Version();
        $main->fromArray(['version' => 'dev-main', 'defaultBranch' => true, 'time' => '2026-10-01T00:00:00+00:00', 'description' => 'Current']);
        $old = new Version();
        $old->fromArray(['version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00', 'description' => 'Old']);
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('getVersions')->willReturn([$main, $old]);
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('get')->with('example/bundle')->willReturn($metadata);
        $client->expects(self::never())->method('getComposer');
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('serialize')->with($main, 'json')->willReturn('{"version":"dev-main"}');
        $package = new Package('example/bundle');
        $this->handler($client, $serializer)->onLoadComposer($this->event($package));
        self::assertSame('dev-main', $package->version);
        self::assertSame('Current', $package->description);
        self::assertSame(['version' => 'dev-main'], $package->data);
    }

    public function testFetchFailureReachesMessengerInsteadOfBeingAcknowledged(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willThrowException(new \RuntimeException('upstream unavailable'));
        $this->expectExceptionMessage('upstream unavailable');
        $this->handler($client, $this->createStub(SerializerInterface::class))->onLoadComposer($this->event(new Package('example/bundle')));
    }

    public function testLatestReleaseIsUsedWhenDefaultBranchIsMissing(): void
    {
        $old = new Version();
        $old->fromArray(['version' => '1.9.0', 'time' => '2020-01-01T00:00:00+00:00']);
        $latest = new Version();
        $latest->fromArray(['version' => '1.10.0', 'time' => '2021-01-01T00:00:00+00:00']);
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('getVersions')->willReturn([$old, $latest]);
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn($metadata);
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())->method('serialize')->with($latest, 'json')->willReturn('{}');
        $package = new Package('example/bundle');
        $this->handler($client, $serializer)->onLoadComposer($this->event($package));
        self::assertSame('1.10.0', $package->version);
    }

    public function testMissingPackageRecordsARejectionReasonWithoutInventingMetadata(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('get')->willThrowException(new \Packagist\Api\PackageNotFoundException('Not found', 404));
        $package = new Package('example/bundle');
        $this->handler($client, $this->createStub(SerializerInterface::class))->onLoadComposer($this->event($package));
        self::assertSame('package_not_found', $package->rejectionReason);
        self::assertNull($package->data);
    }

    public function testEmptyVersionListRecordsARejectionReason(): void
    {
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('getVersions')->willReturn([]);
        $client = $this->createStub(Client::class);
        $client->method('get')->willReturn($metadata);
        $package = new Package('example/bundle');
        $this->handler($client, $this->createStub(SerializerInterface::class))->onLoadComposer($this->event($package));
        self::assertSame('no_versions', $package->rejectionReason);
        self::assertNull($package->data);
    }

    private function event(Package $package): TransitionEvent
    {
        return new TransitionEvent($package, new Marking(['discovered' => 1]), new Transition('load', 'discovered', 'metadata_loaded'));
    }

    private function handler(Client $client, SerializerInterface $serializer): PackageWorkflow
    {
        return new PackageWorkflow($serializer,
            $this->createStub(PackageService::class),
            new \App\Service\RequiredPackageDiscovery($this->createStub(PackageRepository::class), $this->createStub(EntityManagerInterface::class), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore())),
            $client,
            new \App\Service\PackageDocsFetcher(new \Symfony\Component\HttpClient\MockHttpClient()),
            new \App\Service\CanonicalProbe(new \Symfony\Component\HttpClient\MockHttpClient()),
            new \App\Service\PackageEmbeddingService(new \Symfony\Component\HttpClient\MockHttpClient(), $this->createStub(EntityManagerInterface::class), 'http://localhost', 'test', 'revision'));
    }
}
