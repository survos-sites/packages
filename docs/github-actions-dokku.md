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
2. Install `ops/packages-ci-ssh` root-owned and executable at
   `/usr/local/bin/packages-ci-ssh`. Install `ops/sshd_config_packages_ci` as `/etc/ssh/sshd_config_packages_ci`
   and `ops/packages-ci-sshd.service` as a systemd unit. Put the dedicated public
   key, prefixed with `restrict`, in root-owned
   `/etc/ssh/packages-ci.authorized_keys`. Validate with `sshd -t -f` before
   enabling the service. Allow TCP 2222 in the Hetzner firewall; keep the existing
   port 22 rules unchanged. This listener allows only the `dokku` user, that
   one key, and the forced command; shells, passwords and forwarding are disabled.
   The wrapper accepts only this workflow's packages commands. Set environment secret `DOKKU_SSH_PRIVATE_KEY` to its private
   key. Do not copy a personal administrative SSH key into GitHub.
3. Set environment secret `DOKKU_SSH_KNOWN_HOSTS` to the independently verified
   SSH host-key line(s) for `[fsn1.survos.com]:2222`. Do not trust a fresh unauthenticated
   `ssh-keyscan` result inside the deployment workflow.
4. The deployment job passes its read-only, short-lived `GITHUB_TOKEN` over
   SSH stdin. The forced-command wrapper logs into GHCR using a temporary
   `DOCKER_CONFIG`, and deletes it after deployment. No long-lived registry
   token is stored on fsn1. Ensure this repository has read access to its package.
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

The SSH hostname is fixed as `fsn1.survos.com`, on port 2222, using the local alias `fsn1`.
Application runtime secrets remain in Dokku; CI receives only its deployment
key and its automatic per-job registry token. The deployment job validates the live Doctrine schema and public health
endpoint after Dokku's normal deployment checks succeed. Before calling the
first release complete, also verify a database-backed page, a compiled asset,
and any currently scaled consumers. A post-release verification failure marks
the workflow failed but does not automatically undo a deployed release.

## Rollback and deployment ownership

Retain prior image digests in GHCR. Redeploy a known-good digest with
`ssh dokku@fsn1 git:from-image packages ghcr.io/survos-sites/packages@sha256:...`.
This does not reverse database migrations. Review schema compatibility first. A private image that has been pruned locally
needs fresh registry authentication to pull again; keep known-good images locally
and retain their digests in GHCR. The CI key requires the registry username and
token on stdin for every image deployment.

Image deployment updates Dokku's app repository to reference the image. The
GitHub source repository remains authoritative. A later `git push dokku` switches
back to source deployment; avoid mixing both methods during a release.

This change does not configure GitHub secrets, register keys, change server
configuration, or enable deployments merely by being merged.

To disable the CI endpoint, stop/disable `packages-ci-sshd`, remove the TCP 2222
firewall rule, and remove its authorized key and GitHub environment secrets.
The administrative SSH service on port 22 is independent.

## fsn1 runtime requirements

Keep `PORT=80` in Dokku config and explicitly map `http:80:80`. The base
FrankenPHP image also exposes the Caddy admin port 2019; automatic port detection
can otherwise select that port. Cloudflare currently provides the public HTTPS
endpoint; do not add an origin HTTPS mapping without an origin certificate.

`REDIS_URL` must point to the authenticated shared Redis service reachable from
containers, not localhost. Values remain in Dokku config. The application uses
`survos.packages` as its cache namespace to avoid collisions with other apps.
Validate the existing packages RabbitMQ user and vhost before enabling consumers;
a running broker container alone does not establish that its metadata store is
healthy. Do not reset broker data to repair a failed authentication check.

The November state migration supports both the older PostgreSQL enum `marking`
column and the later JSONB `places` column. It preserves checkpoints and document
content. The first image deployment backup is retained on fsn1 at
`/var/backups/packages/bundles2-before-ci-20261119.dump` (root-readable only).
