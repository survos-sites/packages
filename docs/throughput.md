# Local throughput, 2026-11-07

Symfony 8.2.0-BETA1; initially jwage dev-main b797cd8, selected locally with a
phpamqplib DSN. HTTP cache retained throughout; database not reset.

`app:load-data --no-debug` seeded 15,014 new rows in 15.01 seconds, bringing
the catalogue to 15,024. Initial database: 10 metadata rows and one README.
An existing 14,917-message backlog made initial native-AMQP measurements
unusable: 60.53 seconds of redelivery retries, no new metadata. On request,
purged 29,931 queued messages (old backlog plus fresh kickoff messages), then
`state:iterate Package --marking=discovered --no-debug` queued exactly 15,014
unfinished rows. No duplicate redispatch is needed for normal setup.

| Worker layout | Wall seconds | New metadata rows | Metadata rows/sec | New README bodies |
|---|---:|---:|---:|---:|
| Combined docs + load, concurrency 8 | 61.00 | 51 | 0.84 | 20 |
| Combined docs + load, concurrency 32 | 60.63 | 56 | 0.92 | 40 |
| Separate load 32 + docs 8 | 61.01 load, docs allowed 65 seconds | 29 | 0.48 | 17 |

These sequential samples use different packages and cache states. They do not
establish a controlled concurrency speedup or maximum network throughput.
README counts include only bodies actually found. Composer metadata comes from
Packagist, not a literal repository composer.json download. Only compatible
Symfony bundles fetch README/AGENTS; missing files are valid outcomes.
Per-package transition-lock retries occurred; no terminal failures were present
at the final queue check. Runtime dependency discovery also adds new rows/work,
so queue-size deltas are not equivalent to successful package loads.

ES consumer ran with concurrency 4 during the later samples and drained its
queue to zero. After the samples: 15,143 rows, 146 with metadata, 78 README
bodies, 88 documentation attempts, no pending docs. The load is incomplete.

## PR #166 sample

Installed the fork through Composer, pinned in composer.lock to b546b3e
(`dev-codex/fix-consumer-prefetch-resubscription`). Configured package prefetch is
1; RabbitMQ 4.3.5 confirms the running concurrent consumers use prefetch 32.
The earlier samples above were throttled by the eager-subscription bug.

With package concurrency 32 and a separate ES consumer at concurrency 4:

- 1,583 additional metadata records in 20.98 seconds: **75.45 records/sec**.
- 409 additional README bodies; 503 additional completed documentation attempts.
- Worker exited cleanly (0). Documentation pending rose from 75 to 441, so this
  is metadata throughput with overlapping docs work, not end-to-end completion.
- HTTP cache retained; packages differ from earlier runs. No controlled speedup
  factor or cold-network throughput is claimed.
- One non-UTF-8 README reached the failure transport. Invalid UTF-8/null bytes
  are rejected before PostgreSQL persistence to avoid PHP #25299 crashing the
  parallel worker. HTTP failures are similarly wrapped without live responses
  while Symfony #67982 remains unresolved.

## Larger PR run

The subsequent run added 14,351 metadata records and 2,431 README bodies in
167.01 seconds (85.93 metadata records/sec including startup and queue drain).
The first 125 seconds averaged 106.47 metadata records/sec. There were 2,916
additional completed documentation attempts. The first roughly 30 seconds also
had the preceding docs-only drain consumer active; this was not a controlled
comparison between worker layouts.

Both package queues drained and the worker exited 0. Final database counts:
16,497 total rows, 16,461 with metadata, 3,029 README bodies, 3,652 docs attempts.
However, 3,697 rows still have docs_pending (3,679 requirements-compatible and
18 requirements-pending), and 36 rows remain discovered despite empty queues.
This needs investigation; empty queues do **not** establish a complete import.
Three malformed UTF-8 documents are in the failure transport.

Elasticsearch ran throughout but accumulated a backlog. Its worker was changed
from concurrency 4 to the serial batch-handler setup already used in Procfile;
the backlog then decreased substantially. Search completion is not included in
the package timing. Raw samples: `var/benchmark/pr166-backlog.json`.
Composer data is Packagist version metadata, not a literal composer.json download.

Raw local logs and count snapshots: `var/benchmark/`. HTTP cache is preserved
for a later deliberate database reset. Recheck queue/DB status before restarting
or resetting; delayed retries can outlive a consumer.
