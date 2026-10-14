<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PackageCatalogMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '';
        if (!str_starts_with($url, 'postgresql://') && !str_starts_with($url, 'postgres://')) {
            self::markTestSkipped('PostgreSQL is required for the native enum migration.');
        }
        $this->connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url));
        $this->connection->beginTransaction();
        $schema = 'migration_test_'.bin2hex(random_bytes(6));
        $this->connection->executeStatement('CREATE SCHEMA '.$schema);
        $this->connection->executeStatement('SET LOCAL search_path TO '.$schema.', public');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->rollBack();
            $this->connection->close();
        }
    }

    public function testFreshCatalogCanBeCreated(): void
    {
        $this->migrate(new Schema());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM package'));
        self::assertSame(5, (int) $this->connection->fetchOne("SELECT count(*) FROM pg_enum WHERE enumtypid = 'package_place'::regtype"));
    }

    public function testLegacyRowsAndAbandonmentSurviveUpgrade(): void
    {
        $this->connection->executeStatement('CREATE TABLE package (id VARCHAR(255) PRIMARY KEY, marking VARCHAR(32), data JSON, readme TEXT)');
        $this->connection->executeStatement("INSERT INTO package VALUES ('old', 'abandoned', NULL, 'keep this README'), ('replacement', 'documented', '{\"abandoned\":\"new/package\"}', NULL), ('active', NULL, '{\"abandoned\":false}', NULL)");
        $schema = new Schema();
        $schema->createTable('package')->addColumn('marking', 'string');
        $this->migrate($schema);
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM package'));
        self::assertSame(3, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM package WHERE marking = 'discovered'"));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM package WHERE abandoned'));
        self::assertSame('keep this README', $this->connection->fetchOne("SELECT readme FROM package WHERE id = 'old'"));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM package WHERE requirements_failure_reason IS NOT NULL'));
    }

    private function migrate(Schema $schema): void
    {
        foreach (['20261014104544', '20261014120000', '20261014123000'] as $version) {
            require_once dirname(__DIR__).'/migrations/Version'.$version.'.php';
            $class = 'DoctrineMigrations\\Version'.$version;
            $migration = new $class($this->connection, new NullLogger());
            $migration->up($schema);
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }
}
