<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704094712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE command_process (id VARCHAR(26) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, command VARCHAR(255) NOT NULL, cli TEXT DEFAULT NULL, mode VARCHAR(16) NOT NULL, host VARCHAR(128) DEFAULT NULL, pid INT DEFAULT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, exit_code INT DEFAULT NULL, memory_bytes INT DEFAULT NULL, output TEXT DEFAULT NULL, failure_message TEXT DEFAULT NULL, slots JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_command_process_status ON command_process (status)');
        $this->addSql('CREATE INDEX idx_command_process_command ON command_process (command)');
        $this->addSql('CREATE INDEX idx_command_process_created ON command_process (created_at)');
        $this->addSql('ALTER TABLE package ADD pending_steps JSON DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA toolkit_experimental');
        $this->addSql('CREATE SCHEMA timescaledb_information');
        $this->addSql('CREATE SCHEMA timescaledb_experimental');
        $this->addSql('CREATE SCHEMA _timescaledb_internal');
        $this->addSql('CREATE SCHEMA _timescaledb_functions');
        $this->addSql('CREATE SCHEMA _timescaledb_config');
        $this->addSql('CREATE SCHEMA _timescaledb_catalog');
        $this->addSql('CREATE SCHEMA _timescaledb_cache');
        $this->addSql('DROP TABLE command_process');
        $this->addSql('ALTER TABLE package DROP pending_steps');
    }
}
