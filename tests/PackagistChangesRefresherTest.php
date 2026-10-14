<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

use App\Entity\FeedCursor;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Service\PackagistChangesRefresher;
use App\Workflow\PackageWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final class PackagistChangesRefresherTest extends TestCase
{
    public function testFirstRunOnlyStoresTheCursor(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (FeedCursor $c): bool => $c->id === FeedCursor::PACKAGIST && $c->position === '17914610650011'
        ));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $requested = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$requested) {
            $requested = $url;

            return new JsonMockResponse(['error' => 'Invalid or missing "since"', 'timestamp' => 17914610650011], ['http_code' => 400]);
        }, 'https://packagist.org');

        $changes = $this->refresher($http, $em, $this->createStub(PackageRepository::class), $bus, $this->createStub(WorkflowInterface::class))->refresh();

        self::assertTrue($changes->initialized);
        self::assertSame('https://packagist.org/metadata/changes.json', $requested);
    }

    public function testQueuesLoadOncePerTrackedPackageAndAdvancesTheCursor(): void
    {
        $cursor = new FeedCursor(FeedCursor::PACKAGIST, '100');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($cursor);
        $settled = new Package('acme/settled-bundle');
        $settled->marking = PackagePlace::Documented;
        $busy = new Package('acme/busy-bundle');
        $busy->marking = PackagePlace::Discovered;
        $packages = $this->createStub(PackageRepository::class);
        $packages->method('find')->willReturnCallback(static fn (string $id): ?Package => ['acme--settled-bundle' => $settled, 'acme--busy-bundle' => $busy][$id] ?? null);
        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (TransitionRequest $m, array $stamps) use (&$dispatched): Envelope {
            $dispatched[] = [$m->subject->id, $m->transition, $stamps[0]->getTransportNames()];

            return new Envelope($m);
        });
        $http = new MockHttpClient(static fn (string $method, string $url) => new JsonMockResponse([
            'timestamp' => 200,
            'actions' => [
                ['type' => 'update', 'package' => 'acme/settled-bundle', 'time' => 1],
                ['type' => 'update', 'package' => 'acme/settled-bundle~dev', 'time' => 2],
                ['type' => 'update', 'package' => 'acme/busy-bundle', 'time' => 3],
                ['type' => 'update', 'package' => 'other/untracked', 'time' => 4],
                ['type' => 'delete', 'package' => 'gone/bundle', 'time' => 5],
            ],
        ]), 'https://packagist.org');

        $changes = $this->refresher($http, $em, $packages, $bus, $workflow)->refresh();

        self::assertFalse($changes->initialized);
        self::assertSame(3, $changes->changed);
        self::assertSame(['acme/settled-bundle'], $changes->queued);
        self::assertSame(['acme/busy-bundle'], $changes->skipped);
        self::assertSame(['gone/bundle'], $changes->deleted);
        self::assertSame([['acme--settled-bundle', WF::TRANSITION_LOAD, ['bundle.load']]], $dispatched);
        self::assertSame('200', $cursor->position);
    }

    public function testExpiredCursorIsReportedAndReset(): void
    {
        $cursor = new FeedCursor(FeedCursor::PACKAGIST, '1');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($cursor);
        $http = new MockHttpClient(new JsonMockResponse(['error' => 'Invalid or missing "since"', 'timestamp' => 300], ['http_code' => 400]), 'https://packagist.org');

        $changes = $this->refresher($http, $em, $this->createStub(PackageRepository::class), $this->createStub(MessageBusInterface::class), $this->createStub(WorkflowInterface::class))->refresh();

        self::assertTrue($changes->resync);
        self::assertSame('300', $cursor->position);
    }

    private function refresher(MockHttpClient $http, EntityManagerInterface $em, PackageRepository $packages, MessageBusInterface $bus, WorkflowInterface $workflow): PackagistChangesRefresher
    {
        $transition = new Transition(WF::TRANSITION_LOAD, PackagePlace::Documented->value, PackagePlace::MetadataLoaded->value);
        $metadata = new \SplObjectStorage();
        $metadata[$transition] = ['async' => true, 'transport' => 'bundle.load'];
        $routingWorkflow = new StateMachine(new Definition([PackagePlace::Documented->value, PackagePlace::MetadataLoaded->value], [$transition], PackagePlace::Documented->value, new InMemoryMetadataStore([], [], $metadata)));
        $dispatcher = new TransitionDispatcher($bus, new WorkflowLocator(new ServiceLocator([WF::WORKFLOW_NAME => fn () => $routingWorkflow])), new TransitionRouter(null, ['bundle.load']));

        return new PackagistChangesRefresher($http, $em, $packages, $dispatcher, $workflow);
    }
}
