<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Tests\Support\RemoteFixtures;
use App\Workflow\PackagePlace;
use App\Workflow\PackageWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The packages pipeline on the real container: Doctrine, the bundles' kickoff
 * listener, subject store, Messenger handler and lock middleware. Only HTTP
 * (recorded responses) and the queues (serialized in-memory) are substituted.
 */
final class PackageWorkflowPipelineTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM '.Package::class.' p')->execute();
    }

    public function testCanonicalRecheckPreservesMetadataAndDocuments(): void
    {
        $package = new Package('yaboroda/ybsy-file-bundle');
        $package->state = 'documented';
        $package->data = ['type' => 'symfony-bundle', 'source' => ['url' => 'https://bitbucket.org/yaboroda/ybsy-file-bundle.git']];
        $package->readme = 'Preserved README';
        $package->agentsMd = 'Preserved instructions';
        $package->docsFetchedAt = new \DateTimeImmutable('2026-10-01');
        $data = $package->data;
        $this->em->persist($package);
        $this->em->flush();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:canonical:recheck'));
        self::assertSame(0, $tester->execute(['--name' => $package->name]));
        $this->em->clear();
        $package = $this->em->getRepository(Package::class)->findOneBy(['name' => 'yaboroda/ybsy-file-bundle']);
        self::assertSame('rejected', $package->state);
        self::assertSame('canonical_unavailable', $package->rejectionReason);
        self::assertSame($data, $package->data);
        self::assertSame('Preserved README', $package->readme);
        self::assertSame('Preserved instructions', $package->agentsMd);
        self::assertSame('2026-10-01', $package->docsFetchedAt->format('Y-m-d'));
        self::assertCount(0, $this->sent('bundle.load'));
        self::assertCount(0, $this->sent('bundle.fetch.docs'));
    }

    public function testPersistedPackageIsKickedOffAndWorkersFinishIt(): void
    {
        $this->em->persist(new Package(RemoteFixtures::PACKAGE));
        $this->em->flush();

        $load = $this->sent('bundle.load');
        self::assertCount(1, $load);
        self::assertSame(WF::TRANSITION_LOAD, $load[0]->transition);
        self::assertSame(Package::class, $load[0]->subject->type);
        self::assertEquals(PackagePlace::marking(PackagePlace::Discovered), $this->fresh()->getMarking());

        $this->work('bundle.load');
        $package = $this->fresh();
        // load and accept finish in the worker; docs are queued after acceptance.
        self::assertEquals(PackagePlace::marking(PackagePlace::Accepted), $package->getMarking());
        self::assertCount(1, $this->sent('bundle.fetch.docs'));
        // Discovered dependencies were persisted and kicked off too.
        self::assertGreaterThan(1, count($this->sent('bundle.load')));

        $this->work('bundle.fetch.docs');
        $package = $this->fresh();
        self::assertEquals(PackagePlace::marking(PackagePlace::Documented), $package->getMarking());
        self::assertSame(RemoteFixtures::README, $package->readme);
    }

    public function testCascadeNoneStopsAfterTheRequestedTransition(): void
    {
        $package = $this->packageWithoutKickoff();
        self::getContainer()->get(TransitionExecutorInterface::class)->execute($this->workflow(), $package, WF::TRANSITION_LOAD, ['cascade' => 'none']);
        $this->work('bundle.load');
        self::assertEquals(PackagePlace::marking(PackagePlace::MetadataLoaded), $this->fresh()->getMarking());
        self::assertCount(0, $this->sent('bundle.fetch.docs'));
    }

    public function testSyncRunFinishesInProcessWithoutQueues(): void
    {
        self::getContainer()->get(TransitionExecutorInterface::class)->synchronously(function (): void {
            $this->em->persist(new Package(RemoteFixtures::PACKAGE));
            $this->em->flush();
        });

        $package = $this->fresh();
        self::assertEquals(PackagePlace::marking(PackagePlace::Documented), $package->getMarking());
        self::assertSame(RemoteFixtures::README, $package->readme);
        self::assertCount(0, $this->sent('bundle.load'));
        self::assertCount(0, $this->sent('bundle.fetch.docs'));
    }

    public function testRefreshReloadsMetadataAndRefetchesDocs(): void
    {
        $executor = self::getContainer()->get(TransitionExecutorInterface::class);
        $executor->synchronously(function (): void {
            $this->em->persist(new Package(RemoteFixtures::PACKAGE));
            $this->em->flush();
        });
        $package = $this->fresh();
        $package->readme = 'stale';
        $this->em->flush();

        $executor->synchronously(fn () => $executor->execute($this->workflow(), $package, WF::TRANSITION_LOAD));
        $this->em->clear();

        $package = $this->fresh();
        self::assertEquals(PackagePlace::marking(PackagePlace::Documented), $package->getMarking());
        self::assertSame(RemoteFixtures::README, $package->readme, 'docs are always re-fetched on refresh');
    }

    public function testDirectNativeApplyContinuesAfterFlush(): void
    {
        $package = $this->packageWithoutKickoff();
        $this->workflow()->apply($package, WF::TRANSITION_LOAD, ['cascade' => 'sync']);
        self::assertEquals(PackagePlace::marking(PackagePlace::MetadataLoaded), $package->getMarking());
        self::assertCount(0, $this->sent('bundle.load'));

        $this->em->flush();
        self::assertEquals(PackagePlace::marking(PackagePlace::Documented), $this->fresh()->getMarking());
        self::assertCount(0, $this->sent('bundle.fetch.docs'));
    }

    public function testIterateRequeuesDiscoveredPackagesAndStatsCountsThem(): void
    {
        $this->packageWithoutKickoff();
        $iterate = new CommandTester((new Application(self::$kernel))->find('state:iterate'));
        $iterate->execute(['class-name' => 'Package', '--marking' => 'discovered']);
        $iterate->assertCommandIsSuccessful();
        self::assertStringContainsString('1 queued', $iterate->getDisplay());
        self::assertCount(1, $this->sent('bundle.load'));

        $this->transport('bundle.load')->reset();
        $iterate->execute(['class-name' => Package::class, '--marking' => 'discovered', '--sync' => true]);
        $iterate->assertCommandIsSuccessful();
        self::assertEquals(PackagePlace::marking(PackagePlace::Documented), $this->fresh()->getMarking());
        self::assertCount(0, $this->sent('bundle.fetch.docs'));

        $stats = new CommandTester((new Application(self::$kernel))->find('state:stats'));
        $stats->execute(['class-name' => 'Package']);
        $stats->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/documented\s+[1-9][0-9]*\s/', $stats->getDisplay());
    }

    /** A package already in the database at `discovered`, as after an import. */
    private function packageWithoutKickoff(): Package
    {
        $package = new Package(RemoteFixtures::PACKAGE);
        $this->em->persist($package);
        $this->em->flush();
        $this->transport('bundle.load')->reset();

        return $package;
    }

    private function fresh(): Package
    {
        $this->em->clear();

        return $this->em->find(Package::class, Package::idFromName(RemoteFixtures::PACKAGE));
    }

    private function workflow(): WorkflowInterface
    {
        return self::getContainer()->get('workflow.registry')->get(new Package('acme/any'), WF::WORKFLOW_NAME);
    }

    private function transport(string $name): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.'.$name);
    }

    /** @return list<TransitionRequest> */
    private function sent(string $name): array
    {
        return array_values(array_map(static fn ($envelope) => $envelope->getMessage(), array_filter(
            $this->transport($name)->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof TransitionRequest,
        )));
    }

    private function work(string $name): void
    {
        $events = self::getContainer()->get('event_dispatcher');
        $events->addSubscriber($stop = new StopWorkerOnMessageLimitListener(1));
        try {
            (new Worker([$name => $this->transport($name)], self::getContainer()->get('messenger.default_bus'), $events))->run(['sleep' => 0]);
        } finally {
            $events->removeSubscriber($stop);
        }
        self::assertCount(0, $this->transport($name)->getRejected(), $name.' rejected a message');
    }
}
