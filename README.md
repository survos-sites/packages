# Symfony Bundle Browser

This application downloads Symfony bundles from packagist.org and makes it easy to search by version.
It served as the original test case for `survos/api-grid-bundle`, and is now the testbed for
running a survos-sites app under FrankenPHP.

It is also the reference example for [`survos/schema-org-bundle`](https://github.com/survos/schema-org-bundle)
— see [Schema.org / JSON-LD](#schemaorg--json-ld) below.

Configure a Postgres database and set it in .env.local, then run

```bash
git clone git@github.com:survos-sites/packages.git && cd packages
bin/console list

```

```bash
# 1. Seed package names; new rows automatically queue their initial load.
php bin/console app:load-data --no-debug

# 2. Consume metadata and documentation together (docs first avoids starvation).
php bin/console messenger:consume bundle.fetch.docs bundle.load --concurrency=32 --no-debug

# In another terminal, keep Elasticsearch current.
php bin/console messenger:consume elastic --no-debug

# 3. Inspect actual completed rows as well as queue counts.
php bin/console state:stats Package
php bin/console messenger:stats
```

For a bounded throughput sample, add `--time-limit=60` and wrap the consumer
with `/usr/bin/time -p`. Compare database metadata/documentation counts before
and after; drained messages may include stale work or retries. Keep the HTTP
cache, but label these timings as cache-retaining runs, not cold downloads.
`--stop-when-empty` is useful for draining, but delayed retries can remain.
Composer data comes from Packagist's version metadata, not a separate download
of the repository's literal `composer.json`. README and AGENTS.md are fetched
for accepted bundles and dependency libraries. A confirmed missing README rejects the package.

Unavailable metadata ends in `rejected`, with `rejectionReason` set to
`package_not_found` (Packagist 404) or `no_versions` (empty version list).
PHP/Symfony incompatibility uses the same terminal state with its own reason.
Transient failures still use Messenger retries. An explicit `load` can recheck a rejection.

Canonical repositories are probed at the end of `accept`, before its ordered
`next: [canonical_failed, fetch_docs]` guards run. Confirmed unavailable repositories
finish in the existing `rejected` state with `canonical_unavailable`; successful
probes allow docs and dependency discovery. Access errors, rate limits and network
failures are inconclusive and retry, not permanent rejections. Probe evidence is
stored on the entity and definitive results are reused for 24 hours.

After migrating an existing installation, rebuild the search mapping from local
rows with `php bin/console elastic:index:rebuild app_package --no-debug`.
Recheck existing accepted/documented packages without reloading metadata or docs:

```bash
# GITHUB_TOKEN enables batched GitHub GraphQL repository checks.
# For a local authenticated gh session (the token is not printed):
GITHUB_TOKEN=$(gh auth token) php bin/console app:canonical:recheck --no-debug
# Optional: --name=vendor/package, --force to bypass the probe cache.
php bin/console messenger:consume elastic --no-debug
```

The recheck retains inconclusive outcomes for another pass and skips a rate-limited provider for the rest of the pass.
Packages already rejected by PHP/Symfony criteria are not probed. A normal `load`
refresh re-evaluates previously rejected packages. Existing rows use their stored
source URL; new loads also retain Packagist's canonical repository URL.

Documentation is stored as UTF-8. Valid UTF-8 is preserved; UTF-16 with a BOM,
ASCII UTF-16LE runs appended by mixed editors, and legacy Windows-1252 text are
normalized before persistence. Binary control bytes still fail explicitly.


The updated jwage transport is selected by a `phpamqplib://` DSN in
`MESSENGER_TRANSPORT_DSN_RABBITMQ`; `amqp://` selects Symfony's native transport.
The local benchmark uses jwage. Both are installed; installing jwage alone does
not switch the active transport.

New Package rows start automatically after persistence using the initial place's
`next: [load]`. Do not follow setup with a second `state:iterate ... --marking=discovered`
dispatch: it would duplicate the queued work. `--dispatch` remains a compatibility
option on `app:load-data` but no longer redispatches existing rows. `--sync` applies
before insertion so it also controls the automatic kickoff.

The state machine has five places:

```text
discovered --load--> metadata_loaded --accept--> accepted --fetch_docs--> documented
                                    --reject--> rejected
accepted   --canonical_failed--> rejected
documented --readme_missing----> rejected
documented --embed_readme------> documented (stores the vector; no new place)
```

Accepted packages fetch README/AGENTS.md, including libraries. Accepted Symfony
bundles also discover missing runtime `require` packages. Rejection reasons live
on the entity. Platform
requirements and `require-dev` are excluded; libraries do not recursively discover
dependencies. Missing AGENTS.md is allowed; a confirmed missing or blank README is rejected.
Unsupported sources and provider failures are not treated as missing files.

Each package has one persisted `state`; the search `places` facet is derived from it.
See [docs/native-workflow.md](docs/native-workflow.md).

Packages are kept fresh from Packagist's changes feed: a Symfony Scheduler schedule
runs `app:packagist:changes` every 5 minutes and queues a reload only for tracked
packages that changed. One worker consumes the schedule and the work queues with
Symfony 8.2's `--concurrency`; see [docs/scheduler.md](docs/scheduler.md).

```bash
bin/console app:packagist:changes        # first run stores the feed cursor
bin/console messenger:consume scheduler_packagist bundle.fetch.docs bundle.load --concurrency=32 -vv
```

For deliberate recovery of stranded existing rows, inspect the queues first and
use `state:iterate Package --marking=discovered`, which queues each row's `next`
(`load`). `state:stats Package` counts rows by place. Both commands come from
workflow-extras, as does the explorer at `/workflow/PackageWorkflow`.

It takes a while because of fetching Packagist metadata.

## Notes

Purge messages:

```bin
bin/console dbal:run-sql "delete from messenger_messages where queue_name='failed'"
bin/console dbal:run-sql "delete from messenger_messages"
```


curl \
-X PUT 'https://127.0.0.1:8001/meili/indexes/dtdemoOfficial/settings/dictionary' \
-H 'Content-Type: application/json' \
--data-binary '[
"J. R. R.",
"W. E. B."
]'

curl \
-X PUT 'https://127.0.0.1:8001/meili/indexes/dtdemoOfficial/settings/synonyms' \
-H 'Content-Type: application/json' \
--data-binary '{
"great": ["fantastic"], "fantastic": ["great"]
}'

## Schema.org / JSON-LD

Every page publishes a JSON-LD `@graph` describing what is on it, via
`survos/schema-org-bundle`. The mapping lives in
[`src/Schema/PackageSchema.php`](src/Schema/PackageSchema.php).

A bundle is modelled as **`SoftwareSourceCode`** — source you compose into an
application, not an application anyone installs and runs. `SoftwareApplication`
is the type with a Google rich result, but it would be the wrong claim, and
Google's guidance for it expects consumer apps with offers and ratings.

For the same reason, the GitHub star count is **not** an `aggregateRating`. A star
is not a rating: no scale, no reviewer, nothing rated. Stars and Packagist
downloads are published as `interactionStatistic` / `InteractionCounter` with
`LikeAction` and `DownloadAction`, which is the honest encoding.

### Where each part comes from

The declarative half is attributes on `App\Entity\Package`:

```php
#[SchemaOrg('SoftwareSourceCode')]
class Package
{
    #[SchemaProperty('name')]           private(set) readonly ?string $name;
    #[SchemaProperty('description')]    public ?string $description = null;
    #[SchemaProperty('version')]        public ?string $version = null;
    #[SchemaProperty('codeRepository')] public ?string $repo = null;
    #[SchemaProperty('dateModified')]   public ?\DateTimeImmutable $lastUpdated = null;
    #[SchemaProperty('keywords')]       public array $keywords { get => ... }
}
```

Anything requiring a decision stays in `PackageSchema`: composer.json authors →
`Person`, the vendor → maintaining `Organization`, PHP/Symfony constraints →
`runtimePlatform`, stars/downloads → `interactionStatistic`, and the
`WebSite`/`WebPage` wrapping.

### Pages

- **detail** (`/package/{packageId}/`) — one `SoftwareSourceCode` plus its people
- **listing** (`/packages/index`) — a `CollectionPage` whose `mainEntity` is an
  `ItemList` of `ListItem`s, each referencing that package's own node by `@id`;
  30 packages produce ~118 nodes, with shared authors and vendors deduplicated to
  one node each

### auto_inject

`config/packages/survos_schema_org.yaml` sets `auto_inject: true`. The package
detail template extends `@EasyAdmin/layout.html.twig`, which this app does not own
and cannot add `{{ render_schema_org() }}` to, so the bundle inserts the script
before `</head>` instead. `templates/base.html.twig` still calls
`render_schema_org()` explicitly, and that call suppresses the injection for those
requests — so nothing is ever emitted twice.

To see what any page collected, open the **Schema.org** panel in the web debug
toolbar.

## Search index

Search reads the Elasticsearch index `packages_package`. It is not created by
`composer install` (Elasticsearch may not be reachable at build time). On a fresh
checkout, or when the homepage says search is not set up:

```bash
bin/console elastic:index:create     # idempotent; also run on every Dokku deploy
bin/console elastic:index:populate   # bulk-loads every package from the database
```

After a large `app:load-data`, re-run `populate` (or `elastic:index:rebuild` for a
zero-downtime swap); the `elastic` worker only keeps single changes in sync.

## Symfony 8.2 beta setup

Symfony components are locked to `8.2.0-BETA1`. Core requirements remain `^8.1`, with Flex selecting `^8.2` and
`minimum-stability: dev`. Work queues (`bundle.load` and `elastic`) use RabbitMQ;
set `MESSENGER_TRANSPORT_DSN_RABBITMQ` to `phpamqplib://` for jwage or
`amqp://` for Symfony's native AMQP transport. Failed messages use `doctrine://default` in the app database;
a separate Messenger database is no longer needed. The package worker in `Procfile` uses `--concurrency=32`; installing Amp alone does not enable concurrency.

The metadata loader propagates request failures for retry and selects the default
branch, falling back to the highest release or latest branch if necessary. Apply
migration `Version20261002113900` for the updated command bundle's caller column.

Validation on October 2: container and schema checks, production boot, application
tests, homepage and Elasticsearch search passed. `debug:container --deprecations`
still reports legacy API Platform filters and upstream compatibility declarations;
these have not been suppressed. Missing Packagist packages remain inspectable in
the Doctrine failure transport.

The older README fetcher is in `survos/repo`,
`src/Services/AppService.php::syncProjectWithGithub()` (`files: ['readme']`). It
uses GitHub's contents/readme API and stores the base64-decoded body on Project.
It is not connected to this catalogue's workflow.

## PHP configuration tooling

Class-bearing configuration uses PHP and imported `::class` references. Run
`vendor/bin/rector process --config=rector-config.php --dry-run` to check these
files. Standalone service definitions explicitly preserve their previous
autowiring defaults; `App::config()` otherwise enables autowiring automatically.

`config/reference.php` is generated locally and ignored by Git. After a fresh
checkout or dependency changes, run `composer config:reference` to rebuild the
development container and regenerate IDE/static-analysis configuration types.
`composer setup` includes this step. Normal debug container rebuilds regenerate
the file too; production builds with debug disabled do not. Do not set
`APP_DEBUG=0` when running this development setup command.

Local throughput measurements from November 7 are recorded in [docs/throughput.md](docs/throughput.md).


## README policy and semantic search

No additional workflow places are needed. `documented` has ordered
`next: [readme_missing, embed_readme]`: the first rejects a confirmed missing README;
the second queues `bundle.embed` and persists a vector while retaining `documented`.
The guarded embedding self-transition stops once a vector is present. Libraries now
follow the same docs policy as bundles. GitHub, Bitbucket and GitLab sources support
common README filenames; absent files, unsupported sources and HTTP failures are distinct.

```bash
# Review stored packages without reloading Packagist metadata.
php bin/console app:packages:review-readmes --no-debug
# Optional disjoint parallel partitions: --shards=4 --shard=0 (then 1, 2, 3).
php bin/console app:packages:embed --no-debug
php bin/console elastic:index:rebuild package_semantic --no-debug
# Keep normal incremental embedding and search indexing workers running.
php bin/console messenger:consume bundle.embed --no-debug
php bin/console messenger:consume elastic --no-debug
```

`/semantic-search` uses SearchBundle's LiveComponent Lexical/Semantic/Hybrid tabs.
The original InstantSearch browser remains at `/`. Semantic search uses persisted
768-dimensional Nomic vectors, stored in PostgreSQL JSONB and projected into an ES
`dense_vector`; there is no native pgvector column or SQL vector index. Rebuilding
Elasticsearch never calls the model. Model digest, text version and full source text
are fingerprinted; stale/rejected vectors map to null, and vectors/provenance are
excluded from public hit fields. Provenance uses an opaque JSON scalar so automatic
scalar field inference does not expose it through the original browser either.

Set `PACKAGE_OLLAMA_URL`, `PACKAGE_EMBED_MODEL` and `PACKAGE_EMBED_REVISION` for the
local embedding service. The model digest is verified. Full normalized READMEs are
split into 1,500-character chunks, embedded with the Nomic document prefix, combined
with character-weighted mean pooling and normalized. Query text uses the query prefix.
No input is silently truncated. A profile change requires regeneration and reindexing.
`app:packages:embed` is resumable and skips valid cached vectors.
