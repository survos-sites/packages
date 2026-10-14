<?php

declare(strict_types=1);

namespace App\Messenger;

use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Serialize duplicate Package transitions across concurrent Messenger children.
 *
 * Re-entrant within one process: a sync transport (state:iterate --sync) handles a
 * place's `next` transition inside the outer handler's postFlush, while the outer
 * lock is still held. That nested message is the same chain, not a competitor, so
 * it runs under the outer lock and without the refresh (which would discard the
 * outer handler's in-memory state).
 */
final class PackageTransitionLockMiddleware implements MiddlewareInterface
{
    /** @var array<string, int> resource => nesting depth held by this process */
    private array $depth = [];

    public function __construct(
        #[Autowire(service: 'packages.transition_lock.factory')] private readonly LockFactory $locks,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $id = match (true) {
            $message instanceof TransitionRequest && $message->subject->type === 'package' => $message->subject->id,
            default => null,
        };
        if (!$envelope->last(ReceivedStamp::class) || $id === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $resource = 'packages:package:'.$id;
        if (($this->depth[$resource] ?? 0) > 0) {
            ++$this->depth[$resource];
            try {
                return $stack->next()->handle($envelope, $stack);
            } finally {
                --$this->depth[$resource];
            }
        }

        $held = [];
        try {
            $acquire = function (string $resource) use (&$held): void {
                // PostgreSQL session locks have no expiring lease during slow HTTP work.
                $lock = $this->locks->createLock($resource, ttl: null);
                if (!$lock->acquire()) {
                    throw new RecoverableMessageHandlingException('Package transition is already running.', retryDelay: 1000);
                }
                $held[] = $lock;
            };
            $acquire($resource);
            $this->depth[$resource] = 1;
            // Native requests refresh in PackageSubjectStore, inside this lock.
            // Hold through the handler's final flush and postFlush follow-up dispatch.
            return $stack->next()->handle($envelope, $stack);
        } finally {
            unset($this->depth[$resource]);
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }
}
