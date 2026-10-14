# Native Symfony 8.2 workflow

The packages pipeline uses Symfony's native attribute registrar, with
`survos/workflow-extras-bundle` for descriptions and ordered `next` metadata and
`survos/workflow-async-bundle` for queued execution. Every place and transition is
explicitly declared: places in `PackagePlace`, transitions in `BundleWorkflowInterface`.

The existing place values, workflow name, Doctrine marking field and RabbitMQ
queue names are preserved. No database migration is required by this change.

## Execution

1. Persisting a new Package collects it for kickoff after its flush commits.
2. `load` runs on `bundle.load`, reloads the entity under the package lock, and
   invokes the existing native `AsTransitionListener` to fetch Packagist data.
3. The app's `PackagePipeline` selects ordered `next` transitions and applies
   synchronous validation. Libraries may reach `valid`; only compatible Symfony
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

# Refresh one existing package through the transitional CLI adapter.
php bin/console state:iterate Package --transition=load \
  --filter='name=symfony/monolog-bundle' --limit=1

php bin/console messenger:consume bundle.load --time-limit=3600
php bin/console messenger:consume bundle.fetch.docs --time-limit=3600
```

Restart existing workers after updating code/dependencies so they can decode the
new `TransitionRequest` message. Queues were not purged during migration.

## Temporary state-bundle compatibility

State-bundle remains installed for its Graphviz explorer, Twig/marking helpers,
`state:iterate`, and old queued message classes. Its definition reader finds no
legacy attributes in the new definition. Its kickoff and chaining listeners and
its legacy message handler are disabled by the application compiler passes.
Symfony is the sole workflow registrar.

`LegacyPackageTransitionHandler` consumes old `TransitionMessage` payloads using
the new pipeline. Native producers use `TransitionDispatcher` and
`SubjectReference('package', $id)`. Both message types share the existing
PostgreSQL package lock. Old controls honor their synchronous routing option.

The Graphviz page remains `/state/workflow/BundleWorkflow`, with Places and
Transitions tabs, descriptions, next metadata, and async indicators. Its eventual
home is workflow-extras, independent of Messenger.

## Persistence and delivery

This first integration retains the application's single-manager flush model,
including newly discovered dependency packages. It does not install an outbox.
The kickoff/pipeline reject active outer transactions instead of publishing
messages before commit. A crash or broker failure after commit but before dispatch
can leave a row awaiting continuation; explicitly redispatch that transition with
`state:iterate`. Delivery is at least once; reloads and documentation writes are
repeatable, and workers use the existing package lock. New and old message classes
must remain available until old queues and failed messages have drained.

## Checks

`vendor/bin/phpunit` covers native compilation/guards, described constants, kickoff,
feed refresh, locking for both payload types, serialized Messenger workers on both
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

## Planned catalog exclusion: abandoned packages

Decision, not yet implemented: this catalog will exclude abandoned packages.
Skip them during initial discovery when the response establishes abandonment.
For tracked packages, change notifications trigger a metadata refresh; remove a
package only after refreshed metadata confirms abandonment. Remove its search
document as well as its database row, and handle already queued deliveries for a
removed subject deliberately. Dependency discovery must honor the same policy.

Removal belongs after transition processing and persistence/continuation decisions,
not inside a completion listener while the async handler still owns the subject.
Once that path is implemented and existing abandoned rows are handled, remove the
Abandoned place and transition from this example. Until then the current workflow
continues to retain abandoned packages.

## Labels and planned translation

Extras supplies a missing label from the PHP case/constant name during container
compilation. `PackagePlace::Discovered` retains backing value `new`; its default
label is Discovered. Explicit labels remain useful for a different presentation.

Proposed follow-up: `survos_workflow_extras.translation_domain: false` by default,
or a domain string to translate labels and descriptions when rendering. Resolve
translations at request time using the current locale, never during compilation.
The current label/description serves as the key and fallback text. This setting is
not implemented yet; extras should remain usable without Translator when disabled.
