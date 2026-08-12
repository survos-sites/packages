# Dokku's Procfile support overrides the Dockerfile's CMD per process type
# (even under the dockerfile builder), so this must match what the image can
# actually run -- not the old herokuish/nginx+fpm command.
web: frankenphp run --config /etc/caddy/Caddyfile
# Always-on workers, replacing the old app.json `cron` entries (a live consumer
# beats polling every 2-5min). Enable on prod with:
#   dokku ps:scale <app> meili=1 bundle-load=1   |   logs: dokku logs <app> -p meili -t
meili: php -d memory_limit=512M bin/console messenger:consume meili --time-limit=3600 --memory-limit=256M
bundle-load: php -d memory_limit=512M bin/console messenger:consume bundle.load --time-limit=3600 --memory-limit=256M
