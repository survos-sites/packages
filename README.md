# Symfony Bundle Browser

This application downloads Symfony bundles from packagist.org and makes it easy to search by version.
It served as the original test case for `survos/api-grid-bundle`, and is now the testbed for
running a survos-sites app under FrankenPHP.

Configure a Postgres database and set it in .env.local, then run

```bash
git clone git@github.com:survos-sites/packages.git && cd packages
bin/console list

```

```bash
#bin/load-database.sh

bin/console app:load-data
bin/console state:iterate Package --marking=new --transition=load --limit 3
bin/console mess:stats

bin/console mess:consume bundle.load --limit 1 -vv
```

It takes a while because of scraping packagist.

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
