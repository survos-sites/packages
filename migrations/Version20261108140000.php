<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Record canonical repository URL and cached probe evidence'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD canonical_url VARCHAR(255) DEFAULT NULL, ADD canonical_probe JSONB DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP canonical_url, DROP canonical_probe');
    }
}
