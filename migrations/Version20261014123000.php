<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the first incompatible requirement, checking PHP before Symfony';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD requirements_failure_reason VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP requirements_failure_reason');
    }
}
