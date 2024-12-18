dbname=packages
bin/console d:d:c --if-not-exists
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v --setup --limit 1000
#bin/console app:load-data -v --fetch
#bin/console app:load-data -v --fetch --process
#bin/console app:load-data -v  --process

bin/console dbal:run-sql "delete from package where marking='abandoned'"
bin/console grid:index --reset
