dbname=packages
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v --setup --fetch
bin/console app:load-data -v  --process
bin/console grid:index --reset
