dbname=packages
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v --setup
bin/console app:load-data -v --fetch --process
bin/console app:load-data -v  --process
bin/console grid:index --reset
