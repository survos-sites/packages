<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the Packagist changes-feed cursor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feed_cursor (id VARCHAR(32) NOT NULL, position BIGINT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feed_cursor');
    }
}
