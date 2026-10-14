# Build a package catalog with Symfony workflows

## Assignment

Create a multi-part, reproducible tutorial using this repository as the application readers build. Start with native Symfony Workflow only; introduce workflow-extras and workflow-messenger only after readers understand the problems they solve. Finish with safe concurrent processing and an optional searchable catalog using search-bundle. Prepare publication-ready drafts, not automatic publication. Medium, Substack, or both remain an editorial decision.

Teach in an approachable, incremental workshop style with the precision of a framework book: short explanations, concrete examples, visible results, deliberate exercises, and explicit checkpoints. Use original prose rather than copying SymfonyCasts or Symfony documentation. Assume readers know basic PHP, Composer, Symfony services and Doctrine, but not Workflow or Messenger concurrency.

## Chapters

1. **A package catalog and its lifecycle.** Introduce Packagist bundles versus ordinary dependencies. Build a small fixture-driven catalog, explain state machine versus multiple-place workflow, and draw the initial graph. Start with native attributes, a string-backed places enum and a native marking store. No Survos bundles in this checkpoint.
2. **Requirements are checks, not a place for every condition.** Load metadata, evaluate PHP and declared Symfony core constraints for libraries and bundles alike, and choose compatible or unsupported. Missing constraints are not applicable, not proof of compatibility. Record the first failure reason (PHP before Symfony). Explain guards versus listeners, reloading, and why abandonment is data rather than a workflow place.
3. **Persistence and trustworthy transitions.** Doctrine enum mapping, database enforcement and migrations; initial place versus entity initialization; compiled guard tests and metadata extraction tests. Keep transport, HTTP and database operations out of guards. Discuss what a single-state model cannot express.
4. **Make the workflow explain itself with extras.** Replace only the attributes that gain useful metadata. Introduce descriptions, humanized labels, ordered next candidates, icons and optional UX Icons. Walk through the explorer: grouped transition paths, visible guards, icons separate from async execution indicators. Explain catalog introspection and the temporary upstream-tag fallback.
5. **From transitions to background work with async.** Introduce Messenger, transition requests, subject reload, persistence boundaries and explicit chaining. Extras owns common metadata; async appends execution options. Separate metadata and documentation queues. Demonstrate retries and failed-message inspection. Do not imply that metadata next values execute anything on their own.
6. **Operate the pipeline and discover dependencies.** Real Packagist imports, bounded batches, runtime dependency discovery, change-feed refresh, app-owned state:iterate/state:stats commands and caching. Start with five packages before importing the catalog. Explain that compatible libraries stop before bundle-only documentation fetching.
7. **Scale safely with --concurrency.** Establish a sequential baseline, run multiple workers, demonstrate per-package locking, redelivery and repeat-safe processing. Investigate shared dependency insert races and prove the chosen handling before claiming safety. Measure throughput, memory, queue depth and failures; distinguish loaded names from fully processed packages. Include clean shutdown and recovery exercises.
8. **Optional: turn the pipeline into a searchable catalog.** Add search-bundle with its actual index backend dependencies, API/grid views, workflow-state facets and failure explanations. Cover schema changes, reindexing and eventual consistency. Show the payoff without making search a requirement for learning Workflow.

## Chapter contract

Each chapter includes: objective, starting tag/commit, prerequisites, focused changes, complete runnable commands, expected output, a diagram or screenshot where useful, meaningful tests, one failure/recovery exercise, recap and next checkpoint. Keep code executable from a clean checkout at every checkpoint. Use native synchronous fixtures before introducing network services. Provide setup/cleanup and small-data commands; never require the reader to drop an existing database casually.

## Repository and verification work

- Inspect current main and identify the minimal native-only starting point. Do not present current final code as if extras/async were absent.
- Create an agreed chapter branch/tag strategy after reviewing the repository; do not rewrite main history. Checkpoints must isolate each dependency addition and be tested individually.
- Pin exact PHP/Symfony/bundle versions and record publication date. Symfony 8.2 is currently development software; re-check APIs before publication and explain preview requirements accurately.
- Verify optional integrations and every command in clean environments. Native PostgreSQL enum support here is application-specific, not automatic Doctrine behavior for every database.
- Add diagrams and screenshots from the actual chapter checkpoint. Include an accessibility pass: labels and failure text must convey meaning without color.
- Review the current hard-coded Symfony component recognition list and version targets before making broad compatibility claims. This is declared-constraint compatibility, not a guarantee the package runs correctly.

## Current implementation context

Application entity: Package. Workflow: PackageWorkflow. Places: discovered, metadata_loaded, requirements_compatible, requirements_unsupported, documented. Same requirements gate for all package types; docs fetching is bundle-only. Package stores abandonment/replacement and first failure code. The previous state-bundle, code-bundle and EasyAdmin integration have been removed. Commands state:iterate/state:stats are currently app-owned, not generic extras commands.

Extras owns common attributes, metadata, next selection, catalog and optional explorer. Async extends extras without rearranging common arguments and adds async/transport. UI icon arguments are being added after metadata and before async-only arguments. Do not promise translation_domain/source_locale: those were discussed, not implemented.

Symfony issue https://github.com/symfony/symfony/issues/66925 and PR https://github.com/symfony/symfony/pull/66926 propose supports/definition_class service-tag metadata. Until available, extras enriches missing tags from attributes already inspected for labels; runtime eligibility remains native Registry responsibility. Historical issue: https://github.com/symfony/symfony/issues/19938. Verify merge status before writing.

At planning time the local catalog contains about 14,918 package names; a four-worker smoke test completed 29 packages including discovered dependencies. This is not evidence that the full catalog has processed successfully. Markings were reset by the simplified-place migration for reevaluation. Do not publish credentials, local host paths, profiler tokens or private operational details.

## Publication deliverables

Deliver the series introduction, chapter drafts with repository-relative source, tested checkpoints, screenshots/diagrams, troubleshooting appendix and publication checklist. Keep Markdown in this repository as the editorial source. Recommend one primary publication home; assess current Medium/Substack formatting and cross-post/canonical capabilities before choosing a distribution strategy. If both are used, adapt introductions and subscribe links while retaining one maintained technical source. Publication requires the user's explicit go-ahead.

## Acceptance criteria

A reader can progress from native Workflow to concurrent processing without unexplained bundle behavior; every checkpoint builds and tests; commands and expected results are verified; boundaries between native/extras/async/app responsibilities are explicit; no production-scale or compatibility claim exceeds measured evidence. Present the outline for editorial review before drafting the full series.

### Runnable synchronous URL demo

Run `bash docs/tutorial/create-url-workflow-demo.sh /tmp/url-workflow` to build a
fresh Symfony next webapp with the published workflow-extras bundle. Requires
Symfony CLI, Composer, PHP 8.5+, and PDO SQLite. An existing destination is refused.
Optional trailing arguments replace the default URLs; use trusted URLs because
this command makes real HTTP requests.

The script creates a backed enum, native attribute workflow, SQLite migration,
loader, and homepage. Insertion triggers `discovered → validated → fetched`, or
`discovered → invalid`. A fetch is an attempt: HTTP errors retain their status and
body byte count; transport failures populate `error`. `next: []` means stop.

Workflow-extras 2.42.6 or newer owns Doctrine kickoff, persistence, and
next-transition selection; no application continuation adapter is needed. The
loader only persists and flushes. This is an extras-only example: no workflow-messenger bundle,
queue, or worker. SQLite stores enum backing strings; this example does not create
a native database enum. Run `symfony server:start` in the generated directory to
view the saved results.
