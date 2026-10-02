<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/** Serialize duplicate Package transitions across concurrent Messenger children. */
final readonly class PackageTransitionLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'packages.transition_lock.factory')] private LockFactory $locks,
        private EntityManagerInterface $em,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (!$envelope->last(ReceivedStamp::class) || !$message instanceof TransitionMessage
            || $message->getClassName() !== Package::class) {
            return $stack->next()->handle($envelope, $stack);
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
            $acquire('packages:package:'.$message->getId());
            $package = $this->em->find(Package::class, $message->getId());
            if ($package instanceof Package) {
                $this->em->refresh($package);
            }
            // Hold through the handler's final flush and postFlush follow-up dispatch.
            return $stack->next()->handle($envelope, $stack);
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release();
            }
        }
    }
}
