dbname=packages
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v --setup --limit 200
bin/console app:load-data -v --fetch --process
bin/console app:load-data -v  --process
bin/console d:query:sql "delete from package where marking='abandoned'"
bin/console grid:index --reset
