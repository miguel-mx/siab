<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Archiving for analyses: when set, the run is hidden from the history and left
 * out of every aggregate (ArchivedRunFilter), while staying on file so figures
 * already published from it remain reproducible.
 */
final class Version20260803184416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analysis_run.discarded_at (archived analyses)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run ADD discarded_at DATETIME DEFAULT NULL');
        // Every query the filter touches carries "discarded_at IS NULL", which is
        // most of the dashboard; existing rows are all NULL, so this stays selective.
        $this->addSql('CREATE INDEX idx_run_discarded ON analysis_run (discarded_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_run_discarded ON analysis_run');
        $this->addSql('ALTER TABLE analysis_run DROP discarded_at');
    }
}
