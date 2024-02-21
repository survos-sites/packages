dbname=packages
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v --setup --fetch --process
bin/console grid:index --reset
