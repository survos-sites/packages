dbname=packages
#bin/console d:d:c --if-not-exists
#symfony console doctrine:migrations:migrate -n
bin/console app:load-data
bin/console  workflow:iterate App\\Entity\\Package --marking=new --transition=load -v --limit 1

bin/console dbal:run-sql "delete from package where marking='abandoned'"
bin/console grid:index --reset
