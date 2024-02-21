dbname=packages
symfony console doctrine:migrations:migrate -n
bin/console app:load-data -v
bin/console grid:index --reset
