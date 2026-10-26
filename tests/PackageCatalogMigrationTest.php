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
            self::markTestSkipped('PostgreSQL is required for the catalog migration.');
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
        self::assertSame('jsonb', $this->connection->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'package' AND column_name = 'places'"));
    }

    private function migrate(Schema $schema): void
    {
        foreach (['20261014104544', '20261014123000'] as $version) {
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
