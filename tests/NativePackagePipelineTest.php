<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\PackagePlace;

use App\Entity\Package;
use App\Workflow\PackageWorkflow as WF;
use App\Workflow\PackagePipeline;
use App\Workflow\PackageSubjectStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Survos\WorkflowAsyncBundle\Service\TransitionDispatcher;
use Survos\WorkflowAsyncBundle\Service\TransitionHandler;
use Survos\WorkflowAsyncBundle\Service\TransitionRouter;
use Survos\WorkflowAsyncBundle\Service\WorkflowLocator;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;

final class NativePackagePipelineTest extends KernelTestCase
{
    public function testWorkersPersistValidationBeforeDispatchingDocsAndStaleDocsAreBlocked(): void
    {
        [$pipeline, $package, $transports, $bus, $state] = $this->pipeline();
        $pipeline->kickoff($package);
        self::assertSame(PackagePlace::Discovered, $package->marking);
        self::assertCount(1, $transports['bundle.load']->getSent());
        self::assertCount(0, $transports['bundle.fetch.docs']->getSent());
        $this->work('bundle.load', $transports, $bus);
        self::assertSame(PackagePlace::RequirementsCompatible, $state->package->marking);
        self::assertCount(1, $transports['bundle.fetch.docs']->getSent());
        $queuedDocs = $transports['bundle.fetch.docs']->getSent()[0];
        $this->work('bundle.fetch.docs', $transports, $bus);
        self::assertSame(PackagePlace::Documented, $state->package->marking);
        self::assertSame('# Package README', $state->package->readme);
        // A redelivery after the successful commit is acknowledged without re-running work.
        $transports['bundle.fetch.docs']->send($queuedDocs);
        $this->work('bundle.fetch.docs', $transports, $bus);
        self::assertCount(2, $transports['bundle.fetch.docs']->getAcknowledged());
        self::assertSame(PackagePlace::Documented, $state->package->marking);
    }

    public function testCascadeNoneContextSurvivesWorkerPersistence(): void
    {
        [$pipeline, $package, $transports, $bus, $state] = $this->pipeline();
        $pipeline->dispatch($package, WF::TRANSITION_LOAD, ['cascade' => 'none']);
        $this->work('bundle.load', $transports, $bus);
        self::assertSame(PackagePlace::MetadataLoaded, $state->package->marking);
        self::assertCount(0, $transports['bundle.fetch.docs']->getSent());
    }

    public function testSyncPipelineUsesNoQueues(): void
    {
        [$pipeline, $package, $transports, , $state] = $this->pipeline();
        $pipeline->sync = true;
        $pipeline->kickoff($package);
        self::assertSame(PackagePlace::Documented, $state->package->marking);
        self::assertCount(0, $transports['bundle.load']->getSent());
        self::assertCount(0, $transports['bundle.fetch.docs']->getSent());
    }

    public function testDirectNativeApplyContinuesOnlyAfterFlush(): void
    {
        [, $package, $transports, , $state, $workflow, $em] = $this->pipeline();
        $workflow->apply($package, WF::TRANSITION_LOAD, ['cascade' => 'sync']);
        self::assertSame(PackagePlace::MetadataLoaded, $package->marking);
        self::assertSame(PackagePlace::Discovered, $state->package->marking);
        $em->flush();
        self::assertSame(PackagePlace::Documented, $state->package->marking);
        self::assertCount(0, $transports['bundle.fetch.docs']->getSent());
    }

    private function pipeline(): array
    {
        self::bootKernel();
        $package = new Package('acme/example-bundle');
        $state = (object) ['package' => clone $package];
        $loaded = null;
        $listener = null;
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('find')->willReturnCallback(function () use ($state, &$loaded) { return $loaded = clone $state->package; });
        $em->method('flush')->willReturnCallback(function () use ($state, &$loaded, $package, &$listener, $em) { $state->package = clone ($loaded ?? $package); $listener?->postFlush(new \Doctrine\ORM\Event\PostFlushEventArgs($em)); });
        $native = self::getContainer()->get('workflow.registry')->get($package, WF::WORKFLOW_NAME);
        $events = new EventDispatcher();
        // Use the compiled native definition, with deterministic I/O listeners.
        $events->addListener('workflow.'.WF::WORKFLOW_NAME.'.transition.'.WF::TRANSITION_LOAD, static function (TransitionEvent $event): void {
            $subject = $event->getSubject();
            $subject->data = ['type' => 'symfony-bundle'];
            $subject->phpVersions = ['8.5'];
            $subject->symfonyVersions = ['8.2'];
        });
        $events->addListener('workflow.'.WF::WORKFLOW_NAME.'.transition.'.WF::TRANSITION_FETCH_DOCS, static function (TransitionEvent $event): void {
            $event->getSubject()->readme = '# Package README';
        });
        foreach ((new \ReflectionClass(WF::class))->getReflectionConstants() as $constant) {
            foreach ($constant->getAttributes(Transition::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $guard = $attribute->newInstance()->guard;
                if ($guard === null) continue;
                $events->addListener('workflow.'.WF::WORKFLOW_NAME.'.guard.'.$constant->getValue(), static function (GuardEvent $event) use ($guard): void {
                    if (!(new ExpressionLanguage())->evaluate($guard, ['subject' => $event->getSubject()])) $event->setBlocked(true);
                });
                break;
            }
        }
        $workflow = new StateMachine($native->getDefinition(), new MethodMarkingStore(true, 'marking'), $events, WF::WORKFLOW_NAME);
        $transports = ['bundle.load' => new InMemoryTransport(new PhpSerializer()), 'bundle.fetch.docs' => new InMemoryTransport(new PhpSerializer())];
        $handler = null;
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([], new ServiceLocator(array_map(static fn ($transport) => static fn () => $transport, $transports)))),
            new HandleMessageMiddleware(new HandlersLocator([TransitionRequest::class => [static function (TransitionRequest $request) use (&$handler) { return $handler($request); }]])),
        ]);
        $locator = new WorkflowLocator(new ServiceLocator([WF::WORKFLOW_NAME => static fn () => $workflow]));
        $router = new TransitionRouter(null, array_keys($transports));
        $dispatcher = new TransitionDispatcher($bus, $locator, $router);
        $pipeline = new PackagePipeline($workflow, new NextTransitionResolver(), $dispatcher, $em);
        $listener = new \App\Workflow\PackageKickoffListener($pipeline);
        $events->addListener('workflow.'.WF::WORKFLOW_NAME.'.completed', $listener->completed(...));
        $handler = new TransitionHandler(new PackageSubjectStore($em, $pipeline), $locator, $router);
        return [$pipeline, $package, $transports, $bus, $state, $workflow, $em];
    }

    private function work(string $name, array $transports, MessageBus $bus): void
    {
        self::assertNotEmpty($transports[$name]->getSent());
        $events = new EventDispatcher();
        $events->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        (new Worker([$name => $transports[$name]], $bus, $events))->run(['sleep' => 0]);
        self::assertCount(0, $transports[$name]->getRejected());
    }
}
