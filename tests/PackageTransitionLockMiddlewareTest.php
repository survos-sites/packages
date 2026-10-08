<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Messenger\PackageTransitionLockMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Message\TransitionMessage;
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
        $em = $this->createMock(EntityManagerInterface::class);
        // Only the outer message refreshes; the nested one must keep the outer state.
        $em->expects(self::once())->method('refresh');
        $em->method('find')->willReturn(new Package('acme/demo'));
        $middleware = new PackageTransitionLockMiddleware($locks, $em);

        $handled = [];
        $handler = new class($middleware, $handled) implements MiddlewareInterface {
            public function __construct(private PackageTransitionLockMiddleware $middleware, private array &$handled) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $transition = $envelope->getMessage()->transitionName;
                $this->handled[] = $transition;
                if ('valid' === $transition) {
                    // What the sync transport does from the outer handler's postFlush.
                    $this->middleware->handle(self::envelope('fetch_docs'), new StackMiddleware($this));
                }

                return $envelope;
            }

            public static function envelope(string $transition): Envelope
            {
                return new Envelope(new TransitionMessage('acme--demo', Package::class, $transition, 'BundleWorkflow'), [new ReceivedStamp('sync')]);
            }
        };

        $middleware->handle($handler::envelope('valid'), new StackMiddleware($handler));

        self::assertSame(['valid', 'fetch_docs'], $handled);
        self::assertTrue($locks->createLock('packages:package:acme--demo')->acquire(), 'Lock must be released afterwards.');
    }

    public function testAnotherHolderStillBlocks(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $competitor = $locks->createLock('packages:package:acme--demo');
        $competitor->acquire();
        $middleware = new PackageTransitionLockMiddleware($locks, $this->createStub(EntityManagerInterface::class));

        $this->expectException(RecoverableMessageHandlingException::class);
        $middleware->handle(
            new Envelope(new TransitionMessage('acme--demo', Package::class, 'load', 'BundleWorkflow'), [new ReceivedStamp('bundle.load')]),
            new StackMiddleware(),
        );
    }
}
