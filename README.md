# Symfony Bundle Browser

This application downloads Symfony bundles from packagist.org and makes it easy to search by version.

Configure a Postgres database and set it in .env.local, then run

```bash
#bin/load-database.sh

bin/console app:load-data
bin/console workflow:iterate App\\Entity\\Package --marking=new --transition=load
bin/console mess:stats
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
