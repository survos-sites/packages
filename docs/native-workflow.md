# Native Symfony 8.2 workflow

The packages pipeline uses Symfony's native attribute registrar, with
`survos/workflow-extras-bundle` for descriptions and ordered `next` metadata and
`survos/workflow-messenger-bundle` for queued execution. Every place and transition is
explicitly declared: places in `PackagePlace`, transitions in `PackageWorkflow`.

The local database is `packages`. `Package::marking` is a non-null `PackagePlace`
enum, backed by PostgreSQL's `package_place` enum. The baseline migration fixes the
allowed values explicitly; adding a persisted place requires a schema migration.
The constructor/property default is `Discovered`, matching the workflow's initial
place. For an uninitialized subject, native `getMarking()` initializes the configured
initial marking; merely constructing an entity does not invoke the workflow.

The workflow definition and its event listeners live together in `PackageWorkflow`.
Places are declared separately in `PackagePlace`. Queue names remain `bundle.load`
and `bundle.fetch.docs`, even though dependency packages share the metadata queue.

## Execution

1. Persisting a new Package collects it for kickoff after its flush commits.
2. `load` runs on `bundle.load`, reloads the entity under the package lock, and
   invokes the existing native `AsTransitionListener` to fetch Packagist data.
3. The app's `PackagePipeline` selects ordered `next` transitions and applies
   synchronous validation. Libraries may reach `requirements_compatible`; only compatible Symfony
   bundles continue to documentation.
4. `PackageSubjectStore` flushes the resulting marking and data, then the
   pipeline queues `fetch_docs` on `bundle.fetch.docs`.
5. The documentation worker fetches README/AGENTS.md and persists `documented`.
   Missing documents are valid results; other HTTP failures use Messenger retries.

The subject store receives the original transition context, including
`cascade: none`. `app:load-data --sync` executes the whole pipeline synchronously.
Extras itself requires no Messenger: `next` selects candidates; this application's
pipeline decides whether to apply or queue them.

```bash
# New packages start automatically after persistence.
php bin/console app:load-data --vendor=symfony --name=monolog-bundle --limit=1

# Refresh one existing package through the application command.
php bin/console state:iterate Package --transition=load \
  --filter='name=symfony/monolog-bundle' --limit=1

php bin/console messenger:consume bundle.load --time-limit=3600
php bin/console messenger:consume bundle.fetch.docs --time-limit=3600
```

## Discovery and explorer

State-bundle, code-bundle, EasyAdmin and ez-bundle are removed. No marking trait or
marker interface is needed. Symfony's Registry resolves workflows for objects.
Extras' `WorkflowCatalog` supplies declared support classes for tooling before an
entity exists, with multiple workflows allowed for a supported class.

Until Symfony PR #66926 is available, extras enriches native workflow service tags
from the workflow attributes it already reads for labels. Native tag declarations
take precedence. Unknown declarations (including configuration-only workflows on
older Symfony versions) remain empty; custom support strategies are never inferred.

The explorer is `/state/workflow/PackageWorkflow`, route `survos_workflow_extras`.
Its template, graph rendering and catalog are in workflow-extras; the application
owns the HTTP route and its package-specific iteration command. The new
`state:iterate` supports transition, marking, property filters, limit, sync and
cascade options; unsupported legacy reset/dump options are deliberately absent.

```bash
php bin/console state:stats Package
php bin/console messenger:consume bundle.load bundle.fetch.docs elastic --concurrency=4
```

## Persistence and delivery

This first integration retains the application's single-manager flush model,
including newly discovered dependency packages. It does not install an outbox.
The kickoff/pipeline reject active outer transactions instead of publishing
messages before commit. A crash or broker failure after commit but before dispatch
can leave a row awaiting continuation; explicitly redispatch that transition with
`state:iterate`. Delivery is at least once; reloads and documentation writes are
repeatable, and workers use the existing package lock. Legacy message handling was removed after confirming the workflow queues were empty
and resetting the local database, including its failure transport.

## Checks

`vendor/bin/phpunit` covers native compilation/guards, described constants, kickoff,
feed refresh, native request locking, serialized Messenger workers on both
queues, save-before-follow-up dispatch, stale docs delivery and synchronous/context
behavior. The worker tests use deterministic I/O and a mocked persistence adapter;
they do not drain the application's broker or alter its data.

## Attribute ownership

`PackagePlace` uses extras' `Place(description, label, next, initial, metadata)`.
`next` accepts one transition name or an ordered list; both become list metadata.
Extras' `Transition(from, to, description, label, next, guard, metadata)` describes
synchronous paths. Queued paths use async's `Transition` imported as
`AsyncTransition`: it extends extras with the same common arguments, then appends
`async` and `transport`. Async requires extras; extras needs no Messenger runtime.
Use named arguments after description. Native `apply()` still runs synchronously.

## Abandoned packages

Abandonment is package metadata, not a workflow place. `abandoned` and `replacement`
are refreshed from Packagist. Initial bundle discovery skips already abandoned
packages, but a discovered dependency or later-abandoned package can still be
validated and documented normally. There is no Abandoned place or abandon transition.

## Labels and planned translation

Extras supplies a missing label from the PHP case/constant name during container
compilation. `PackagePlace::Discovered` uses backing value `discovered`; its default
label is Discovered. Explicit labels remain useful for a different presentation.

Proposed follow-up: `survos_workflow_extras.translation_domain: false` by default,
or a domain string to translate labels and descriptions when rendering. Resolve
translations at request time using the current locale, never during compilation.
The current label/description serves as the key and fallback text. This setting is
not implemented yet; extras should remain usable without Translator when disabled.

## One requirements check

Bundles and libraries use the same requirements gate. PHP and declared Symfony
core constraints are checked together, irrespective of Composer package type.
Missing constraints are `not_applicable`, which passes the overall gate; they
are not evidence of tested compatibility. Contracts, polyfills and other
independently versioned Symfony packages are not Symfony core version constraints.

The package exposes `phpCompatibility` and `symfonyCompatibility` as `unknown`,
`not_applicable`, `compatible` or `incompatible`. Any incompatible result rejects
the requirements. These are metadata results, not separate workflow places.
Compatible libraries stop at requirements compatible; bundles additionally fetch
documentation. Abandonment is a property and does not prevent processing.
