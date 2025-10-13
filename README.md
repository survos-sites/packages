# Symfony Bundle Browser

This application downloads Symfony bundles from packagist.org and makes it easy to search by version.

Configure a Postgres database and set it in .env.local, then run

```bash
#bin/load-database.sh

bin/console app:load-data
bin/console state:iterate Package --marking=new --transition=load --limit 3
bin/console mess:stats

bin/console mess:consume bundle.load --limit 1 -vv
```

It takes a while because of scraping packagist.


```bash
composer config repositories.tacman_packagist_api '{"type": "path", "url": "/home/tac/g/tacman/packagist-api"}'
composer req knplabs/packagist-api:*@dev

composer config repositories.survos_api_grid_bundle '{"type": "vcs", "url": "git@github.com:survos/SurvosApiGridBundle.git"}'
composer req survos/api-grid-bundle:dev-main

```

## Notes

Purge messages:

```bin
bin/console dbal:run-sql "delete from messenger_messages where queue_name='failed'"
bin/console dbal:run-sql "delete from messenger_messages"
```


curl \
-X PUT 'https://127.0.0.1:8001/meili/indexes/dtdemoOfficial/settings/dictionary' \
-H 'Content-Type: application/json' \
--data-binary '[
"J. R. R.",
"W. E. B."
]'

curl \
-X PUT 'https://127.0.0.1:8001/meili/indexes/dtdemoOfficial/settings/synonyms' \
-H 'Content-Type: application/json' \
--data-binary '{
"great": ["fantastic"], "fantastic": ["great"]
}'
