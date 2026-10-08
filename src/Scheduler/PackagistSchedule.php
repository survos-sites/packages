<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Polls Packagist's changes feed. Consumed as the `scheduler_packagist` transport,
 * alongside the work queues it feeds (see doc/scheduler.md):
 *
 *     bin/console messenger:consume scheduler_packagist bundle.load bundle.fetch.docs --concurrency=4
 */
#[AsSchedule('packagist')]
final class PackagistSchedule implements ScheduleProviderInterface
{
    public function __construct(
        // PostgreSQL, not LOCK_DSN (flock): every dokku container has its own filesystem,
        // and the lock must hold across `ps:scale bundle-load=2`.
        #[Autowire(service: 'packages.transition_lock.factory')] private LockFactory $locks,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('app:packagist:changes')))
            // Leader election: Scheduler keeps this lock for the worker's lifetime, so
            // with several consumers only one generates ticks. A PostgreSQL session lock
            // has no TTL and is freed when that worker's connection closes.
            ->lock($this->locks->createLock('schedule:packagist', ttl: null));
        // Not stateful: missed ticks need no catch-up, the feed cursor in feed_cursor
        // replays every change since the last successful run.
    }
}
