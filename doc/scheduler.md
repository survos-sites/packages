# Keeping packages fresh: Symfony Scheduler + `--concurrency` (Symfony 8.2)

This app tracks Symfony bundles from Packagist and runs each one through a workflow
(`load → php_ok → symfony_ok → valid → documented`). This page shows how it stays
current without re-fetching everything: a **schedule** reads Packagist's change feed
every five minutes and queues `load` only for packages that actually changed, and
one **concurrent worker** drains the schedule and the work queues together.

```
           every 5 min                 changed + tracked
scheduler_packagist ──▶ app:packagist:changes ──▶ bundle.load ──▶ … ──▶ bundle.fetch.docs
   (tick)              (reads feed from cursor)     (load)               (README, AGENTS.md)
         └──────────── all consumed by ONE worker, --concurrency=4 ────────────┘
```

## 1. Decide *what* to reload, not *whether* to load

The `load` transition always loads. Deciding "is this package stale?" inside a
transition listener makes the workflow lie: the marking says *loaded* when nothing
was fetched. The decision belongs **before dispatch**, and Packagist publishes
exactly what we need: [the changes feed](https://packagist.org/apidoc#track-package-updates).

```
GET https://packagist.org/metadata/changes.json?since=17914611790011
{"actions":[{"type":"update","package":"acme/foo-bundle~dev","time":1791460439}, …],
 "timestamp":17914611790011}
```

- `since`/`timestamp` are in 1/10 000 s (they need a `BIGINT`).
- `~dev` marks a dev-branch update; we strip it, a package is a package.
- Without `since` (or with one Packagist no longer keeps) the response is an
  `error` plus a fresh `timestamp`, which is how the first run initializes.

[`PackagistChangesRefresher`](../src/Service/PackagistChangesRefresher.php) reads the
feed from a cursor stored in the database ([`FeedCursor`](../src/Entity/FeedCursor.php),
table `feed_cursor`; `var/` does not survive a dokku deploy), and for every tracked
package that changed it dispatches `load`, using state-bundle's queue routing:

```php
$message = new TransitionMessage($package->id, Package::class, WF::TRANSITION_LOAD, WF::WORKFLOW_NAME);
$this->bus->dispatch($message, $this->asyncQueueLocator->stamps($message));
```

Packages in `new` (their kickoff `load` is already queued) or mid-chain are skipped.
`load` may start from every *settled* place (`valid`, `documented`,
`outdated_symfony`, …), so a refresh re-runs the whole chain, docs included.

Run it by hand any time:

```bash
bin/console app:packagist:changes -v
```

Repeat fetches are cheap anyway: the Packagist client sits on a caching scoped
HttpClient (`config/packages/http_client.yaml`), and `/packages/{name}.json` is
cacheable for 12 h (`s-maxage=43200`).

## 2. The schedule

```php
#[AsSchedule('packagist')]
final class PackagistSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'packages.transition_lock.factory')] private LockFactory $locks,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('app:packagist:changes')))
            ->lock($this->locks->createLock('schedule:packagist', ttl: null));
    }
}
```

- **`#[AsSchedule('packagist')]`** creates a Messenger transport named
  `scheduler_packagist`. A schedule is consumed like any queue.
- **`RunCommandMessage`** runs the console command as the message handler, so the
  same code path serves cron-less scheduling *and* manual runs.
- **The lock is a leader election.** Scheduler keeps the lock for as long as the
  worker lives (it only refreshes it between ticks), so when several workers consume
  `scheduler_packagist`, only the holder generates ticks; when it dies, another takes
  over. We use the app's PostgreSQL lock store, **not** `LOCK_DSN` (`flock`): each
  dokku container has its own filesystem, so a file lock would not stop two scaled
  containers from both ticking. A PostgreSQL session lock has no TTL and is released
  when the holder's connection closes, which is the semantics we want.
- **Not `stateful()`.** A stateful schedule replays missed ticks after downtime. We
  don't need that: the feed cursor already replays every change since the last
  successful run, however long the gap.

Check it:

```bash
bin/console debug:scheduler
```

> **8.2 gotcha:** after `composer require symfony/scheduler`, `debug:scheduler` was
> "not defined" and no `scheduler_*` transport existed, even after `cache:clear`.
> In 8.2 components ship their own bundles, and FrameworkBundle pulls
> `SchedulerBundle` in through `#[RequiredBundle]` rather than `config/bundles.php`.
> The compiled kernel kept its old bundle list until `rm -rf var/cache/dev`.
>
> 8.2 also adds `framework.scheduler.schedules.<name>` (`stateful`, `cache_pool`,
> `lock_factory`, `process_only_last_missed_run`) to configure these in YAML instead
> of in the provider.

## 3. One worker, `--concurrency`

Symfony 8.2 adds `messenger:consume --concurrency=N` (it needs `amphp/parallel`).
The **parent** process fetches messages and hands each to one of N **child**
processes, so slow HTTP handlers (Packagist, raw.githubusercontent.com) overlap
instead of queueing behind each other. `--fetch-size` (default: N) controls how many
messages the parent pulls per call.

The Procfile runs the whole pipeline in one process type:

```
bundle-load: php -d memory_limit=512M bin/console messenger:consume scheduler_packagist bundle.load bundle.fetch.docs --concurrency=4 --time-limit=3600 --memory-limit=256M
```

- **Receivers are polled in the order given**, so the schedule's tick is never
  starved by a backlog of `load` messages.
- **Only the parent reads the schedule**, so `--concurrency=4` does not mean four
  ticks; the tick is handled in a child like any other message.
- **Concurrency needs per-entity locking.** Two children can receive transitions for
  the same package. [`PackageTransitionLockMiddleware`](../src/Messenger/PackageTransitionLockMiddleware.php)
  serializes them with a PostgreSQL lock per package (re-entrant within a process,
  so `state:iterate --sync` cascades still work).

### Prefetch: let `--concurrency` size the window

With AMQP the broker *pushes* up to `prefetch_count` messages to a consumer before it
has acknowledged any. In 8.2 the window is `max(prefetch_count, --fetch-size)`, and
`--fetch-size` defaults to `--concurrency`. So keep `prefetch_count: 1` on the
work queues and let the worker's flags raise it.

We learned this the hard way. `bundle.load` had `prefetch_count: 16`, and every
`messenger:consume bundle.load --limit=1` took 16 messages, handled one and exited.
The other 15 went back to the queue flagged *redelivered*, and the next worker's
`RejectRedeliveredMessageMiddleware` (which assumes a redelivered message crashed
its last consumer) sent each one through retry, until after three short runs they
landed in `failed` without ever being handled.

The exception is a **batch handler**. elastic-bundle's `ReindexDocumentsHandler`
holds messages unacknowledged until a batch fills, so its transport needs a window
at least as big as the batch (`elastic` keeps `prefetch_count: 50`). There, avoid
short `--limit` runs.

### Which AMQP transport?

`amqp://` DSNs are built by Symfony's own `symfony/amqp-messenger` (ext-amqp), which
is the transport that supports `--concurrency` and `--fetch-size` here.
`jwage/phpamqplib-messenger` is installed but only answers `phpamqplib://` DSNs, so
it is not involved.

## Running it

Locally:

```bash
bin/console app:packagist:changes                         # first run: stores the cursor
bin/console messenger:consume scheduler_packagist bundle.load bundle.fetch.docs --concurrency=4 -vv
```

The schedule is also listed in zenstruck messenger-monitor at `/admin/messenger`.

What a 7-minute local run of the Procfile worker looked like (2026-10-08, 200
bundles freshly loaded, `--concurrency=4`):

- 271 messages handled. All 200 bundles plus 122 runtime dependencies they pulled
  in settled (`documented`, `outdated_symfony` or `php_is_too_old`).
- The schedule ticked once, five minutes after the worker started
  (`RunCommandMessage … handled successfully`). Ticks are counted from worker start.
- 4 `Package transition is already running`: two children got transitions for the
  same package; the lock middleware made one retry a second later. Expected.
- A second worker on `scheduler_packagist` logged `Failed to acquire …
  schedule:packagist` every second and never ticked: the leader lock at work.

After adding entity fields that are indexed (here `readme`, `agentsMd`), rebuild the
search index, or every `ReindexDocuments` is rejected by the strict mapping:

```bash
bin/console elastic:index:rebuild app_package
```

On dokku the worker is scaled outside the deploy (see the Procfile comments):

```bash
dokku ps:scale packages bundle-load=1
dokku ps:set packages restart-policy unless-stopped   # REQUIRED, see below
```

`messenger:consume --time-limit` exits **0** when the limit is reached, and docker's
default `on-failure` policy does not restart a container that exited 0. Without
`unless-stopped` the worker, and with it the schedule, silently stops an hour after
each deploy. This is the one real advantage cron would have had: it fires even when
no worker runs.
