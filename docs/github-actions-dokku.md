# GitHub Actions image deployment

`.github/workflows/dokku-image.yml` builds the existing production Dockerfile
for `linux/amd64` (fsn1), publishes to `ghcr.io/survos-sites/packages`, then
smoke-tests that exact digest. The image includes the existing `Procfile` and
`app.json`. No Composer credentials or production secrets are needed by the
current build. Builds run only via **Run workflow**, with `deploy=false` by
default; pushes and pull requests cannot trigger this workflow.

Each run gets a commit/run/attempt tag so a rebuild of the same commit does not
replace a previous tag. Deployment uses the digest, never `latest`. The Actions
summary records the digest. Smoke checks validate deployment metadata, packaged
assets, Caddy configuration, and static HTTP serving; they do not replace the
repository's PHPUnit/PHPStan workflows or prove database-backed behavior.

## First build

After reviewing and pushing the workflow to main, manually run **Build image and
deploy to Dokku**, leaving `deploy` false. Review the build log, smoke checks,
and release digest. Confirm the GHCR package visibility and repository access;
keep it private unless public distribution is intentional. The workflow's
`GITHUB_TOKEN` needs permission to write this package.

## One-time deployment setup

1. Create the GitHub `production` environment. Restrict deployments to `main`;
   optionally configure required reviewers if supported by the repository plan.
2. Register a dedicated CI SSH key with Dokku, restricted to the `packages` app
   where supported. Set environment secret `DOKKU_SSH_PRIVATE_KEY` to its private
   key. Do not copy a personal administrative SSH key into GitHub.
3. Set environment secret `DOKKU_SSH_KNOWN_HOSTS` to the independently verified
   SSH host-key line(s) for `fsn1.survos.com`. Do not trust a fresh unauthenticated
   `ssh-keyscan` result inside the deployment workflow.
4. For a private GHCR image, configure Dokku's registry authentication once with
   a dedicated read-packages credential. Do not persist the short-lived Actions
   token as the server's recovery credential. Never log the token.
5. Check effective PostgreSQL configuration and run full
   `php bin/console doctrine:schema:validate` against local PostgreSQL before the
   first production release. Review the existing PHPUnit/PHPStan results too;
   this manual workflow does not automatically wait for those separate checks.
6. Review current Dokku process scaling, storage, config, restart policy and
   release hooks. In particular, the existing predeploy stops workers and runs
   migrations; postdeploy creates the Elasticsearch index and runs `about`.
   Neither database provisioning nor worker scaling belongs to this workflow.
7. Set repository variable `PACKAGES_DOKKU_DEPLOY_ENABLED=true` only when ready.

Run the workflow on `main` with `deploy=true`. It builds, publishes, smoke-tests,
then invokes `git:from-image packages ghcr.io/survos-sites/packages@sha256:...`.
If the enable flag is absent or the selected ref is not main, the deployment job
is skipped even when `deploy=true`. Builds and deployments are serialized, and
an active release is not cancelled by a newer invocation.

The SSH hostname is fixed as `fsn1.survos.com`, using the local alias `fsn1`.
Application runtime secrets remain in Dokku; CI receives only its deployment
key. The deployment job validates the live Doctrine schema and public health
endpoint after Dokku's normal deployment checks succeed. Before calling the
first release complete, also verify a database-backed page, a compiled asset,
and any currently scaled consumers. A post-release verification failure marks
the workflow failed but does not automatically undo a deployed release.

## Rollback and deployment ownership

Retain prior image digests in GHCR. Redeploy a known-good digest with
`ssh dokku@fsn1 git:from-image packages ghcr.io/survos-sites/packages@sha256:...`.
This does not reverse database migrations. Review schema compatibility first.

Image deployment updates Dokku's app repository to reference the image. The
GitHub source repository remains authoritative. A later `git push dokku` switches
back to source deployment; avoid mixing both methods during a release.

This change does not configure GitHub secrets, register keys, change server
configuration, or enable deployments merely by being merged.
