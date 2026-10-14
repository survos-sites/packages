<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\LoadDataCommand;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Workflow\PackageWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Survos\WorkflowExtrasBundle\EventListener\WorkflowKickoffListener;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Service\MessengerTransitionExecutor;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Doctrine\DBAL\Connection;
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
        $load = new Transition('load', 'discovered', 'metadata_loaded');
        $transitions = new \SplObjectStorage();
        $transitions[$load] = ['async' => true, 'transport' => 'bundle.load'];
        $metadata = new InMemoryMetadataStore([], ['discovered' => ['next' => ['load']]], $transitions);
        $workflow = new StateMachine(new Definition(['discovered', 'metadata_loaded'], [$load], 'discovered', $metadata), new MethodMarkingStore(true, 'marking'), null, WF::WORKFLOW_NAME);
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em->method('getConnection')->willReturn($connection);
        $bus = $this->createMock(MessageBusInterface::class);
        $dispatcher = new TransitionDispatcher($bus, new WorkflowLocator(new ServiceLocator([WF::WORKFLOW_NAME => fn () => $workflow])), new TransitionRouter(null, ['bundle.load']));
        $store = new class implements SubjectStoreInterface {
            public function reference(object $subject): SubjectReference { return new SubjectReference(Package::class, $subject->id); }
            public function load(SubjectReference $reference): ?object { return null; }
            public function save(SubjectReference $reference, object $subject, array $context = []): void {}
        };
        $executor = new MessengerTransitionExecutor($dispatcher, $store);
        $catalog = new WorkflowCatalog(new ServiceLocator([WF::WORKFLOW_NAME => fn () => $workflow]), [
            WF::WORKFLOW_NAME => ['supports' => [Package::class], 'support_strategy' => null, 'source_class' => WF::class, 'type' => 'state_machine', 'marking_property' => 'marking'],
        ]);
        $listener = new WorkflowKickoffListener($catalog, new NextTransitionRunner(new NextTransitionResolver(), $executor), $executor);
        $dispatched = [];
        $bus->expects(self::exactly($nested ? 2 : 1))->method('dispatch')->willReturnCallback(function ($message) use ($listener, $em, $nested, &$dispatched) {
            self::assertSame('load', $message->transition);
            self::assertNotContains($message->subject->id, $dispatched);
            $dispatched[] = $message->subject->id;
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
        $command = new LoadDataCommand($repository, $em, $cache, $executor);
        $result = $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), dispatch: true, batch: 1);
        self::assertSame(0, $result);
    }
}
