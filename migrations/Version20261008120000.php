<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store README.md and AGENTS.md fetched by the fetch_docs transition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD readme TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE package ADD agents_md TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE package ADD docs_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP readme');
        $this->addSql('ALTER TABLE package DROP agents_md');
        $this->addSql('ALTER TABLE package DROP docs_fetched_at');
    }
}
