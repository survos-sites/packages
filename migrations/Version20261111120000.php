<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store each package document (README, AGENTS.md) as its own PackageFile row'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE package_file (id VARCHAR(255) NOT NULL, state VARCHAR(32) DEFAULT NULL, extract JSONB DEFAULT NULL, sha VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL, path VARCHAR(255) DEFAULT NULL, content TEXT NOT NULL, fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, package_id VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_37D253D8F44CABFF3BC4BCD9 ON package_file (package_id, kind)');
        $this->addSql('CREATE INDEX IDX_37D253D8F44CABFF ON package_file (package_id)');
        $this->addSql('ALTER TABLE package_file ADD CONSTRAINT FK_37D253D8F44CABFF FOREIGN KEY (package_id) REFERENCES package (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Existing documents become rows at the initial place ('fetched', nothing extracted yet). The id and
        // hash match PackageFile::idFor(); the file name is unknown, so path stays null.
        foreach (['readme' => 'readme', 'agents' => 'agents_md'] as $kind => $column) {
            $this->addSql(sprintf(
                "INSERT INTO package_file (id, state, sha, kind, path, content, fetched_at, package_id)
                 SELECT id || '--%1\$s--' || left(encode(sha256(convert_to(%2\$s, 'UTF8')), 'hex'), 12), 'fetched',
                        encode(sha256(convert_to(%2\$s, 'UTF8')), 'hex'), '%1\$s', NULL, %2\$s,
                        COALESCE(docs_fetched_at, now()), id
                 FROM package WHERE COALESCE(%2\$s, '') ~ '\\S'",
                $kind, $column,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE package_file');
    }
}
