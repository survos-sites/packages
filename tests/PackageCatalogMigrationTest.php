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

    public function testStateMachineMigrationPreservesContentAndRejectionReasons(): void
    {
        $this->connection->executeStatement('CREATE TABLE package (id INT PRIMARY KEY, places JSONB NOT NULL, requirements_failure_reason VARCHAR(32), data JSON, readme TEXT)');
        $cases = [
            [['discovered'], null, 'discovered', null],
            [['metadata_loaded', 'requirements_pending', 'docs_pending'], null, 'metadata_loaded', null],
            [['metadata_loaded', 'requirements_compatible', 'docs_pending'], null, 'accepted', null],
            [['metadata_loaded', 'requirements_compatible', 'docs_skipped'], null, 'accepted', null],
            [['metadata_loaded', 'requirements_compatible', 'documented'], null, 'documented', null],
            [['metadata_loaded', 'requirements_unsupported', 'docs_skipped'], 'php_incompatible', 'rejected', 'php_incompatible'],
            [['package_not_found'], null, 'rejected', 'package_not_found'],
            [['no_versions'], null, 'rejected', 'no_versions'],
        ];
        foreach ($cases as $id => [$places, $reason]) {
            $this->connection->insert('package', ['id' => $id, 'places' => json_encode($places), 'requirements_failure_reason' => $reason, 'data' => '{"type":"library"}', 'readme' => 'Keep this README']);
        }
        require_once dirname(__DIR__).'/migrations/Version20261108120000.php';
        $migration = new \DoctrineMigrations\Version20261108120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
        foreach ($cases as $id => [, , $state, $reason]) {
            $row = $this->connection->fetchAssociative('SELECT * FROM package WHERE id=?', [$id]);
            self::assertSame($state, $row['state']);
            self::assertSame($reason, $row['requirements_failure_reason']);
            self::assertSame('Keep this README', $row['readme']);
            self::assertSame(['type' => 'library'], json_decode($row['data'], true));
            self::assertArrayNotHasKey('places', $row);
        }
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
