<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collapse package markings to discovered/metadata_loaded/accepted/rejected/documented without reloading content';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL is required.');
        // Older production deployments used a single PostgreSQL enum marking.
        // Normalize it to the intermediate representation before the shared
        // conversion below; never reset existing checkpoints or document data.
        $columns = $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'package'");
        if (!in_array('places', $columns, true)) {
            $this->abortIf(!in_array('marking', $columns, true), 'Expected package.places or the legacy package.marking column.');
            $this->addSql('ALTER TABLE package ADD places JSONB');
            $this->addSql('UPDATE package SET places = jsonb_build_array(marking::text)');
            $this->addSql('ALTER TABLE package DROP marking');
        }
        $this->addSql('ALTER TABLE package ADD state VARCHAR(32) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE package SET requirements_failure_reason = CASE
                WHEN places @> '["package_not_found"]' THEN 'package_not_found'
                WHEN places @> '["no_versions"]' THEN 'no_versions'
                ELSE requirements_failure_reason
            END,
            state = CASE
                WHEN places @> '["requirements_unsupported"]'
                    OR places @> '["package_not_found"]'
                    OR places @> '["no_versions"]' THEN 'rejected'
                WHEN places @> '["documented"]' THEN 'documented'
                WHEN places @> '["requirements_compatible"]' THEN 'accepted'
                WHEN places @> '["metadata_loaded"]' THEN 'metadata_loaded'
                ELSE places->>0
            END
            SQL);
        $this->addSql("ALTER TABLE package ADD CONSTRAINT package_state_valid CHECK (state IN ('discovered', 'metadata_loaded', 'accepted', 'rejected', 'documented'))");
        $this->addSql('ALTER TABLE package DROP places');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE package ADD places JSONB NOT NULL DEFAULT \'[]\'::jsonb');
        $this->addSql(<<<'SQL'
            UPDATE package SET places = CASE
                WHEN state = 'rejected' AND requirements_failure_reason = 'package_not_found' THEN '["package_not_found"]'::jsonb
                WHEN state = 'rejected' AND requirements_failure_reason = 'no_versions' THEN '["no_versions"]'::jsonb
                WHEN state = 'rejected' THEN '["metadata_loaded", "requirements_unsupported", "docs_skipped"]'::jsonb
                WHEN state = 'documented' THEN '["metadata_loaded", "requirements_compatible", "documented"]'::jsonb
                WHEN state = 'accepted' AND data->>'type' = 'symfony-bundle' THEN '["metadata_loaded", "requirements_compatible", "docs_pending"]'::jsonb
                WHEN state = 'accepted' THEN '["metadata_loaded", "requirements_compatible", "docs_skipped"]'::jsonb
                WHEN state = 'metadata_loaded' THEN '["metadata_loaded", "requirements_pending", "docs_pending"]'::jsonb
                WHEN state = 'discovered' THEN '["discovered"]'::jsonb
                ELSE '[]'::jsonb
            END
            SQL);
        $this->addSql('ALTER TABLE package DROP state');
    }
}
