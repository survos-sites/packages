<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist README embeddings and their content/model fingerprint'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD embedding JSONB DEFAULT NULL, ADD embedding_fingerprint JSONB DEFAULT NULL, ADD readme_status VARCHAR(16) DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package DROP embedding, DROP embedding_fingerprint, DROP readme_status');
    }
}
