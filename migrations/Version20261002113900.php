<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002113900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the command caller recorded by the updated command bundle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE command_process ADD caller VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE command_process DROP caller');
    }
}
