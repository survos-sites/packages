<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\LoadDataCommand;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Survos\StateBundle\Doctrine\InitialPlaceKickoffListener;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Contracts\Cache\CacheInterface;

final class PackageKickoffTest extends TestCase
{
    public static function nestedDependencies(): array
    {
        return ['ordinary flush' => [false], 'dependency created during sync kickoff' => [true]];
    }

    #[DataProvider('nestedDependencies')]
    public function testSetupWithLegacyDispatchStartsEachNewPackageOnlyOnce(bool $nested): void
    {
        $metadata = new InMemoryMetadataStore([], ['new' => ['next' => ['load']]]);
        $workflow = new StateMachine(new Definition(['new', 'composer_loaded'], [new Transition('load', 'new', 'composer_loaded')], 'new', $metadata), new MethodMarkingStore(true, 'marking'), null, WF::WORKFLOW_NAME);
        $helper = $this->createStub(WorkflowHelperService::class);
        $helper->method('getWorkflowsGroupedByClass')->willReturn([Package::class => [WF::WORKFLOW_NAME]]);
        $helper->method('getWorkflow')->willReturn($workflow);
        $em = $this->createMock(EntityManagerInterface::class);
        $classMetadata = $this->createStub(ClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturnCallback(fn (Package $p) => ['id' => $p->id]);
        $em->method('getClassMetadata')->willReturn($classMetadata);
        $queues = new AsyncQueueLocator([], [], $em);
        $bus = $this->createMock(MessageBusInterface::class);
        $listener = new InitialPlaceKickoffListener($helper, $queues, $bus);
        $dispatched = [];
        $bus->expects(self::exactly($nested ? 2 : 1))->method('dispatch')->willReturnCallback(function ($message) use ($listener, $em, $nested, &$dispatched) {
            self::assertSame('load', $message->transitionName);
            self::assertNotContains($message->id, $dispatched);
            $dispatched[] = $message->id;
            if ($nested && count($dispatched) === 1) {
                $listener->postPersist(new PostPersistEventArgs(new Package('acme/component'), $em));
            }
            // Even a nested postFlush during dispatch must not dispatch again.
            $listener->postFlush(new PostFlushEventArgs($em));
            return new Envelope($message);
        });
        $em->expects(self::once())->method('persist')->willReturnCallback(fn ($package) => $listener->postPersist(new PostPersistEventArgs($package, $em)));
        $em->expects(self::atLeastOnce())->method('flush')->willReturnCallback(fn () => $listener->postFlush(new PostFlushEventArgs($em)));
        $repository = $this->createStub(PackageRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $repository->method('count')->willReturn(1);
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(['acme/bundle' => (object) ['abandoned' => false, 'repository' => 'https://github.com/acme/bundle']]);
        $command = new LoadDataCommand($repository, $em, $cache, $queues);
        $result = $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), dispatch: true, batch: 1);
        self::assertSame(0, $result);
    }
}
