dbname=packages
#bin/console d:d:c --if-not-exists
#symfony console doctrine:migrations:migrate -n
bin/console app:load-data
bin/console workflow:migrate --marking=new --translation=load

bin/console dbal:run-sql "delete from package where marking='abandoned'"
bin/console grid:index --reset
