<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Messenger\PackageTransitionLockMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class PackageTransitionLockMiddlewareTest extends TestCase
{
    public function testNestedSyncTransitionForTheSamePackageRunsUnderTheOuterLock(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $middleware = new PackageTransitionLockMiddleware($locks);

        $handled = [];
        $handler = new class($middleware, $handled) implements MiddlewareInterface {
            public function __construct(private PackageTransitionLockMiddleware $middleware, private array &$handled) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $transition = $envelope->getMessage()->transition;
                $this->handled[] = $transition;
                if ('valid' === $transition) {
                    // What the sync transport does from the outer handler's postFlush.
                    $this->middleware->handle(self::envelope('fetch_docs'), new StackMiddleware($this));
                }

                return $envelope;
            }

            public static function envelope(string $transition): Envelope
            {
                return new Envelope(new TransitionRequest('PackageWorkflow', new SubjectReference('package', 'acme--demo'), $transition, [], 'test'), [new ReceivedStamp('sync')]);
            }
        };

        $middleware->handle($handler::envelope('valid'), new StackMiddleware($handler));

        self::assertSame(['valid', 'fetch_docs'], $handled);
        self::assertTrue($locks->createLock('packages:package:acme--demo')->acquire(), 'Lock must be released afterwards.');
    }

    public function testNativeRequestUsesTheSamePackageLock(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('packages:package:acme--demo');
        $held->acquire();
        $middleware = new PackageTransitionLockMiddleware($locks);
        $this->expectException(RecoverableMessageHandlingException::class);
        $middleware->handle(new Envelope(new \Survos\WorkflowMessengerBundle\Message\TransitionRequest(
            'PackageWorkflow', new \Survos\WorkflowMessengerBundle\Message\SubjectReference('package', 'acme--demo'), 'load', [], 'test',
        ), [new ReceivedStamp('bundle.load')]), new StackMiddleware());
    }

    public function testAnotherHolderStillBlocks(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $competitor = $locks->createLock('packages:package:acme--demo');
        $competitor->acquire();
        $middleware = new PackageTransitionLockMiddleware($locks);

        $this->expectException(RecoverableMessageHandlingException::class);
        $middleware->handle(
            new Envelope(new TransitionRequest('PackageWorkflow', new SubjectReference('package', 'acme--demo'), 'load', [], 'test'), [new ReceivedStamp('bundle.load')]),
            new StackMiddleware(),
        );
    }
}
