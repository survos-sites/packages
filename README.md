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
#bin/load-database.sh

bin/console app:load-data
bin/console mess:stats

bin/console mess:consume bundle.load --limit 1 -vv
```

New Package rows start automatically after persistence using the initial place's
`next: [load]`. Do not follow setup with a second `state:iterate ... --transition=load`
dispatch: it would duplicate the queued work. `--dispatch` remains a compatibility
option on `app:load-data` but no longer redispatches existing rows. `--sync` applies
before insertion so it also controls the automatic kickoff.

When a Symfony bundle completes `valid`, its missing runtime `require` packages
are inserted into the same catalogue and start through the same mechanism.
Platform requirements and `require-dev` are excluded. Libraries do not recursively
discover their dependencies. Composer `type` determines whether Symfony 8 compatibility
checks apply; Symfony 7-only bundles do not pass the catalogue gate, while PHP-compatible libraries can reach `valid` without a Symfony requirement.
`valid` continues to `fetch_docs`, which stores README.md and AGENTS.md from the
source repository and settles in `documented`.

Packages are kept fresh from Packagist's changes feed: a Symfony Scheduler schedule
runs `app:packagist:changes` every 5 minutes and queues `load` only for tracked
packages that changed. One worker consumes the schedule and the work queues with
Symfony 8.2's `--concurrency`; see [doc/scheduler.md](doc/scheduler.md).

```bash
bin/console app:packagist:changes        # first run stores the feed cursor
bin/console messenger:consume scheduler_packagist bundle.load bundle.fetch.docs --concurrency=4 -vv
```

For deliberate recovery of stranded existing rows, inspect the queues first and
use `state:iterate Package --marking=new --transition=load` explicitly.

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

## Symfony 8.2 development setup

Core Symfony requirements remain `^8.1`, with Flex selecting `^8.2` and
`minimum-stability: dev`. Work queues (`bundle.load` and `elastic`) use RabbitMQ;
set `MESSENGER_TRANSPORT_DSN_RABBITMQ` to an `amqp://` URL for Symfony's native
AMQP transport. Failed messages use `doctrine://default` in the app database;
a separate Messenger database is no longer needed. Consumers remain serial in
`Procfile`; installing Amp does not itself enable concurrency.

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
