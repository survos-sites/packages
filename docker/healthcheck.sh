#!/bin/sh
set -eu
command=$(tr '\000' ' ' < /proc/1/cmdline)
case "$command" in
    *messenger:consume*)
        # A worker has no Caddy HTTP server. Queue activity is checked separately.
        kill -0 1
        ;;
    *)
        exec curl --fail --silent --show-error "http://127.0.0.1:${PORT:-80}/health"
        ;;
esac
