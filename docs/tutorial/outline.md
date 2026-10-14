# Series outline — for editorial review

Status: outline only, not drafted. Prepared 2026-10-14 against local `main` at `53ef442`.
The brief is in `series-plan.md`; this file is the proposed answer to it.

## The arc in one paragraph

Readers build one thing: the current `PackageWorkflow`. Five places (`discovered`,
`metadata_loaded`, `requirements_compatible`, `requirements_unsupported`, `documented`)
and four transitions (`load`, `accept_requirements`, `reject_requirements`, `fetch_docs`).
They start with a `Package` entity, a `PackagePlace` enum and native Symfony 8.2
attributes. They write a loader that does `if ($workflow->can()) $workflow->apply()`
and see that it moves each package one step only. They try to chain the steps with
`#[AsCompletedListener]`, which works but feels awkward. Then they add workflow-extras
and `next:`, so the order of steps is declared on the places, and a small loop runs
every package to a terminal place. Only after that do they add workflow-async and
move the slow transitions onto queues. Real Packagist data, concurrency and search
come after the workflow is understood.

## Facts that shape the plan

| Fact (verified 2026-10-14) | Consequence |
|---|---|
| Native `#[AsWorkflow]`, `#[Place]`, `#[Transition]` and `WorkflowBundle` arrive in **Symfony 8.2** (Workflow CHANGELOG). 8.2 is unreleased, with GA planned for 11/2026. | The whole series requires 8.2, from chapter 1 on. Either publish after 8.2.0 GA, or label it a preview and pin an exact 8.2 beta/RC. |
| No commit on `main` is native-only. History goes from state-bundle straight to extras/async. Current `main` loads about 45 bundles (Tabler, API Platform, Elastic, search, …). | A chapter 1 checkpoint cannot be cut from `main`'s history. Checkpoints are built forward from a clean skeleton (see the strategy below). |
| `main` requires `survos/workflow-extras-bundle` and `workflow-async-bundle` as `dev-main`, and the installed copies in `vendor/` are symlinks into a local monorepo. Packagist has tagged 2.41.9 (extras/async) and 2.42.4 (search). | Checkpoints must pin tagged releases. In a fresh worktree of `main` today, `bin/phpunit` shows 28 errors and 3 failures because those symlinks don't resolve. That is a reproducibility gap, not a code bug. |
| Extras' `NextTransitionResolver` only selects a transition. Its docblock says: "The caller decides when to apply or enqueue, after persistence". The loop that applies the selected transition is app code (`PackagePipeline::finish`). | Chapter 3 says this plainly: `next:` is metadata. The reader writes about 30 lines of driver code that read it. |
| `PackageKickoffListener` (start new rows on postFlush) is app code. **It is not in extras or async.** The only equivalent is the legacy `state-bundle`'s `InitialPlaceKickoffListener`, which `main` stopped using. | See the "Kickoff" decision below. |
| symfony/symfony#66926 and #66925 (supports/definition_class on service tags) are both **open**. #19938 is closed. | Chapter 3 describes extras' tag-enrichment fallback as temporary. Re-check before publication. |
| `messenger:consume --concurrency` exists in 8.2 Messenger. | Chapter 7 is possible. Its `amphp/parallel` requirement and transport behaviour still need to be re-verified. |
| The requirements check recognises **18** `symfony/*` components and targets PHP 8.3–8.5 and Symfony 8.0–8.2 (`PackageService::populateFromComposerData`). | A package that requires only, for example, `symfony/http-foundation`, `yaml` or `process` gets "not applicable" for Symfony. The series must say "declared-constraint compatibility for these components" and nothing broader. |

## Checkpoint strategy (proposed)

- Create an orphan branch, `tutorial`, in this repo. It starts from a fresh `symfony/skeleton` 8.2 and is
  never merged into `main`. `main` keeps its history untouched.
- Each chapter ends with a tag: `tutorial-ch01` … `tutorial-ch08`. Chapter N starts from `tutorial-ch(N-1)`.
  Each tag adds at most one new dependency family, and CI runs `composer install && bin/phpunit` on every tag.
- The domain code copies `main` and grows toward it. A script diffs `tutorial-ch07` against `main` for
  `src/Entity/Package.php`, `src/Workflow/*`, `PackageService` and the relevant tests, so the end state
  really is the current workflow. Tabler, API Platform UI and the other bundles stay out of the tutorial.
- Drafts live in `docs/tutorial/NN-*.md` on `main` and cite code by tag and repo-relative path.

| Tag | Adds (composer) | Reader can run |
|---|---|---|
| ch01 | framework, workflow 8.2, doctrine orm/bundle/migrations, PostgreSQL | migrate, `workflow:dump` graph |
| ch02 | composer/semver | `app:load` on JSON fixtures, one step per run |
| ch03 | survos/workflow-extras-bundle (+ optional symfony/ux-icons, twig for the explorer) | fixtures run to a terminal place; explorer page |
| ch04 | — (tests and native PG enum only) | phpunit with compiled guard + metadata tests |
| ch05 | symfony/messenger, doctrine-messenger, survos/workflow-async-bundle | two queues, retry, `messenger:failed:show` |
| ch06 | knplabs/packagist-api, http-client cache, scheduler | five real packages, dependency discovery, change feed |
| ch07 | symfony/lock, amphp/parallel | `--concurrency`, measurements |
| ch08 (optional) | survos/search-bundle + its actual backend (Elastic today) | faceted search by place and failure reason |

## Chapters

Each chapter follows the brief's contract: objective, start tag, prerequisites, changes, commands,
expected output, diagram, tests, one failure/recovery exercise, recap, next checkpoint.

### 1. A package catalog and its lifecycle — native only
- Bundles vs. ordinary Composer dependencies, and why one catalog holds both.
- `Package` entity with a `marking` property typed `PackagePlace` (portable `enumType`, varchar column for now).
- `PackagePlace` string-backed enum with native `#[Place]`. `PackageWorkflow` with native `#[AsWorkflow]` and
  `#[Transition]`. Method marking store with a `BackedEnum`. `initial` place.
- State machine vs. workflow: why one package has exactly one place here.
- Graph via `bin/console workflow:dump PackageWorkflow | dot -Tsvg`. The first graph has no reload edges.
- Exercise: add a bogus transition from a terminal place, see the compile-time error, then remove it.

### 2. A simple loader — one step at a time
- JSON fixtures (no network): about 6 packages, picked to cover a compatible bundle, a compatible library,
  PHP-incompatible, Symfony-incompatible, no Symfony constraints (not applicable), and an abandoned package.
- `app:load`: `if ($workflow->can($p, 'load')) { $workflow->apply($p, 'load'); }`, then flush. Run it and every
  package stops at `metadata_loaded`. Run the accept/reject transitions by hand to see the next step.
- The `load` transition listener fills metadata and computes requirements (PHP before Symfony, first failure
  reason recorded). Guards only read `subject.requirementsCompatible`: guards decide, listeners do work.
- Missing constraints are "not applicable", which is not proof of compatibility. Abandonment is data, not a place.
- Then chain the steps with `#[AsCompletedListener]` calling `apply()` and show what goes wrong: listeners
  hard-code the next transition names, both branches have to be remembered, `apply` nests inside
  another transition's event, and flush placement gets murky. It works, and it doesn't scale.
- Exercise: introduce a typo'd transition name in the listener and diagnose the failure.

### 3. Let the workflow say what comes next — workflow-extras
- Swap native attributes for extras' subclasses only where they add something: description, label, icon, `next:`.
- `next:` is an ordered list of candidates on a place. `NextTransitionResolver` picks the first enabled one.
  **Our** loop applies it, detects cycles and flushes once at the end. The reader writes that loop (the
  synchronous core of today's `PackagePipeline`). `next:` executes nothing by itself.
- Delete the completed-listener chaining from chapter 2. `app:load` now drives each fixture to
  `requirements_unsupported`, `requirements_compatible` (libraries) or `documented` (bundles; docs come from fixtures here).
- Catalog introspection, the explorer (grouped transition paths, visible guards), optional UX Icons. Icons stay
  visually separate from the later async indicator. The tag-enrichment fallback is described until #66926 lands.
- Exercise: reorder the `next` candidates on `metadata_loaded` and explain why the result doesn't change (the guards exclude each other).

### 4. Make the transitions trustworthy
- PostgreSQL native enum type (`PackagePlaceType` + migration). This is application-specific, not
  Doctrine default behaviour, and it gets a portable fallback note.
- Initial place vs. constructor initialisation. Reload edges (`load` from every place) and why they exist.
- Tests: compiled guard expressions (8.2 compiles them at warmup), metadata extraction, terminal-place reachability.
- Rules: no HTTP, transport or DB work in guards. What a single-state model cannot express.
- Exercise: write a raw SQL update that sets an invalid marking, watch the database reject it, recover.

### 5. From transitions to background work — workflow-async
- Messenger basics, using the Doctrine transport so the reader needs no new service. AMQP is shown as an option.
- `async: true, transport:` on the `load` and `fetch_docs` transitions. `TransitionRequest`, subject reload via
  `SubjectStore`, and the committed-transaction boundary. The loop now stops at async transitions and enqueues them.
- Separate `bundle.load` and `bundle.fetch.docs` queues. Retries, `messenger:failed:show/retry`.
- Exercise: make the fixture source throw, watch the retries, inspect the failed message, fix it and retry.

### 6. Operate the pipeline and discover dependencies
- Swap the fixture source for the Packagist client with an HTTP cache. Start with five packages, then a bounded batch.
- Runtime dependency discovery on `accept_requirements`, plus the kickoff of the new rows. Change-feed refresh with Scheduler.
- App-owned `state:iterate` / `state:stats`, and why they are app commands.
- Show that libraries stop at `requirements_compatible`. Only bundles fetch docs.
- Exercise: stop the worker mid-batch, restart, and confirm with `state:stats` that nothing is lost.

### 7. Scale safely with `--concurrency`
- Sequential baseline first. Then `--concurrency=N`, the per-package lock middleware, redelivery and idempotency.
- Investigate the shared-dependency insert race and **prove** how it is handled before claiming it.
- Measure throughput, memory, queue depth and failures. Count "names loaded" separately from "fully processed".
- Clean shutdown (`messenger:stop-workers`) and recovery exercises.

### 8. Optional — a searchable catalog
- search-bundle with its real backend dependency, workflow-place and failure-reason facets, reindexing,
  eventual consistency. Nothing earlier depends on it.

## Decisions needed

1. **Chapter order.** Your arc puts extras at chapter 3 and moves "persistence and trust" to chapter 4,
   directly before async (where committed boundaries matter). Accept?
2. **Kickoff.** Today the "start newly inserted packages after flush" behaviour lives in app code
   (`PackageKickoffListener`), because extras is deliberately selection-only and state-bundle is gone.
   Options: (a) teach it as app code in chapter 6, or (b) first move a generic version into
   workflow-async (it needs Doctrine + the dispatcher, so async fits better than extras), release it, and
   teach that. Recommend (b) if you want it to be bundle behaviour. Otherwise the tutorial
   must not call it extras behaviour.
3. **Checkpoint branch.** Orphan `tutorial` branch + `tutorial-chNN` tags in this repo (recommended), or a separate tutorial repo?
4. **Publish timing.** After Symfony 8.2.0 GA (recommended; November 2026), or a labelled preview pinned to a beta/RC?
5. **Messenger transport in chapter 5.** Doctrine first (recommended, fewer services), with AMQP as the production note?

## Publication home (recommendation)

Primary: **Medium**, with this repo's Markdown as the single maintained source. Medium's import tool keeps a
canonical link and its editor has code blocks with syntax highlighting. Pasting content manually drops the
canonical, so publish through import. Substack cannot set an external canonical URL. If Substack is used at
all, use it for short adapted newsletter introductions that link to the Medium chapter, not for a second copy
of the full chapter. Before choosing, test-import one draft chapter with long PHP and YAML blocks, because
importer handling of code blocks has been unreliable in the past.
