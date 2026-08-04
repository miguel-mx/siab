<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Split the engine's findings in two: `flags` keeps what a person must act on
 * (and is what makes a run "Por revisar"), `notes` takes the provenance lines that
 * fire on every healthy run — which, as flags, made every run ask to be reviewed.
 *
 * Nullable rather than defaulted: existing runs have no notes column to fill, and
 * their flags stay exactly as recorded. Their status is left alone too; only new
 * runs are classified the new way.
 */
final class Version20260803200047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analysis_run.notes (informational findings, kept out of flags)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run ADD notes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run DROP notes');
    }
}
