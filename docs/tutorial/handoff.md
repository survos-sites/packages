# Tutorial series — handoff (paused 2026-10-17)

Tracking issue: https://github.com/survos-sites/steps/issues/1

## Where things stand

- The brief is in `series-plan.md`. The proposed outline is in `outline.md`; it is ready for editorial review and has not been drafted.
- No chapters are drafted. The `tutorial` branch and `tutorial-chNN` tags have not been created.
- `create-url-workflow-demo.sh` was added on main after the outline. It is a runnable extras-only URL demo, described at the end of `series-plan.md`.

## Read this first: the outline is partly out of date

The outline was written 2026-10-14. These later changes on main supersede parts of it:

1. **workflow-async has been renamed workflow-messenger** (see `series-plan.md`). Every "workflow-async" in
   `outline.md` (chapter 5, the checkpoint table, the facts table) should now read workflow-messenger.
   Re-check the attribute namespace and `TransitionRequest`/`SubjectStore` names before drafting chapter 5.
2. **workflow-extras ≥ 2.42.6 owns Doctrine kickoff, persistence and next-transition selection**, according to the
   demo notes in `series-plan.md`. That settles open decision 2 (kickoff) and changes chapter 3. Under the outline's
   original plan, readers wrote their own continuation loop (the synchronous core of `PackagePipeline`). Now the
   chapter 3 payoff can be that adding extras plus `next:` is enough. Chapter 3 must still say which part is
   extras behaviour and which is native Workflow. `next: []` means stop. Verify against the released bundle
   before claiming it, and check whether the app's `PackageKickoffListener`/`PackagePipeline` have been deleted
   or slimmed on main.
3. The URL demo uses **SQLite** and `symfony new --version=next`. The outline proposed PostgreSQL from chapter 1.
   Decide whether chapters 1–3 use SQLite (fewer services; the native PG enum moves to chapter 4) and whether the
   URL demo is a warm-up or a replacement for chapter 1.

## Still-open decisions (from outline.md)

- [ ] Chapter order: extras at chapter 3, trust at chapter 4 (directly before messenger)
- [x] ~~Kickoff: app code vs. bundle~~ — resolved by extras 2.42.6 (verify)
- [ ] Checkpoints: orphan `tutorial` branch with `tutorial-chNN` tags in this repo, or a separate repo / the steps repo
- [ ] Publish after Symfony 8.2.0 GA (planned 11/2026), or as a labelled preview
- [ ] Chapter 5 transport: Doctrine first, AMQP as the production note
- [ ] New: SQLite vs. PostgreSQL for the early chapters (see 3 above)

## Facts verified 2026-10-14 (re-check on resume)

- The native `#[AsWorkflow]`/`#[Place]`/`#[Transition]` attributes and `WorkflowBundle` are Symfony 8.2-only. 8.2 was unreleased then.
- symfony/symfony #66926 and #66925 were open; #19938 is closed.
- `PackageService` checks 18 `symfony/*` components against PHP 8.3–8.5 and Symfony 8.0–8.2. Make only declared-constraint claims.
- In a fresh worktree, `bin/phpunit` failed (28 errors, 3 failures) because `vendor/survos/*` were symlinks into a
  local monorepo. Tutorial checkpoints must pin tagged releases.
- Medium's import tool keeps a canonical link; Substack has no external canonical. The recommendation was Medium
  as the primary home with Substack teasers. Test-import one code-heavy chapter before committing to it.

## Next steps on resume

1. Reconcile `outline.md` with the three changes above, then get editorial sign-off.
2. Create the checkpoint branch and the chapter 1 tag; set up CI per tag.
3. Draft chapter 1 and chapter 2 against those tags.
