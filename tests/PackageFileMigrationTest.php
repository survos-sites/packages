<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Entity\PackageFile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PackageFileMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? '';
        if (!str_starts_with($url, 'postgresql://') && !str_starts_with($url, 'postgres://')) {
            self::markTestSkipped('PostgreSQL is required for the package file migration.');
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

    public function testExistingDocumentsBecomeRowsWithTheIdAndHashTheEntityComputes(): void
    {
        $this->connection->executeStatement('CREATE TABLE package (id VARCHAR PRIMARY KEY, readme TEXT, agents_md TEXT, docs_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE)');
        $readme = "# Bündel — 日本語\n";
        $this->connection->insert('package', ['id' => 'acme--demo', 'readme' => $readme, 'agents_md' => 'Run the tests.']);
        $this->connection->insert('package', ['id' => 'acme--blank', 'readme' => "  \n", 'agents_md' => null]);

        require_once dirname(__DIR__).'/migrations/Version20261111120000.php';
        $migration = new \DoctrineMigrations\Version20261111120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }

        $package = new Package('acme/demo');
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM package_file ORDER BY kind');
        self::assertSame(['agents', 'readme'], array_column($rows, 'kind'), 'a blank document gets no row');
        $expected = [
            PackageFile::AGENTS => PackageFile::idFor($package, PackageFile::AGENTS, hash('sha256', 'Run the tests.')),
            PackageFile::README => PackageFile::idFor($package, PackageFile::README, hash('sha256', $readme)),
        ];
        foreach ($rows as $row) {
            self::assertSame($expected[$row['kind']], $row['id']);
            self::assertSame('fetched', $row['state']);
            self::assertSame('acme--demo', $row['package_id']);
            self::assertNull($row['path']);
        }
        self::assertSame($readme, $rows[1]['content']);
    }
}
