# Dokku's Procfile support overrides the Dockerfile's CMD per process type
# (even under the dockerfile builder), so this must match what the image can
# actually run -- not the old herokuish/nginx+fpm command.
web: frankenphp run --config /etc/caddy/Caddyfile
# Always-on workers, replacing the old app.json `cron` entries (a live consumer
# beats polling every 2-5min). Declared here, but SCALED OUTSIDE THE DEPLOY:
#   dokku ps:scale <app> meili=1 elastic=1 bundle-load=1   |   logs: dokku logs <app> -p meili -t
#
# Scaling outside the deploy is deliberate. Dokku healthchecks every scaled process
# type, and these have no healthcheck of their own, so they are judged on "did the
# container stay up" -- which a messenger:consume against an unreachable transport
# does not. Scaled during the deploy, a broken RabbitMQ would fail the release of a
# web app that was perfectly healthy. Scaled outside it, the same breakage degrades
# background processing and leaves deploys alone. The app.json predeploy runs
# `mess:stop` so the running consumers retire and pick up the new release instead of
# holding the old code.
#
# WARNING -- these need `dokku ps:set <app> restart-policy unless-stopped`.
# `messenger:consume --time-limit` exits 0 when the limit is reached (verified: it
# returns Command::SUCCESS, not a failure), and docker's `on-failure` policy -- the
# dokku default, and what this app is currently set to -- does NOT restart a
# container that exited 0. So under on-failure every worker here dies for good one
# hour after deploy and never returns. zm and ssai are both in that state right now:
# every consumer `exited`, only web running, queues silently growing.
# The elastic worker drains ReindexDocuments/RemoveDocuments from the postFlush
# listener. Without it those messages queue forever and the index silently drifts
# from the database -- searches keep working, they just return stale documents.
meili: php -d memory_limit=512M bin/console messenger:consume meili --time-limit=3600 --memory-limit=256M
elastic: php -d memory_limit=512M bin/console messenger:consume elastic --time-limit=3600 --memory-limit=256M
bundle-load: php -d memory_limit=512M bin/console messenger:consume bundle.load --time-limit=3600 --memory-limit=256M
