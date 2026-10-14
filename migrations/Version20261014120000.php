<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidate requirements places; reload previously evaluated packages under the new rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TYPE package_place RENAME TO package_place_old');
        $this->addSql("CREATE TYPE package_place AS ENUM ('discovered', 'metadata_loaded', 'requirements_compatible', 'requirements_unsupported', 'documented')");
        // Re-fetch old results: libraries previously skipped Symfony dependency checks.
        $this->addSql("ALTER TABLE package ALTER COLUMN marking TYPE package_place USING 'discovered'::package_place");
        $this->addSql('DROP TYPE package_place_old');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Old compatibility places cannot be reconstructed from consolidated results.');
    }
}
