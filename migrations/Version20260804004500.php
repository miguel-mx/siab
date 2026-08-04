<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the "Por revisar" status and the review marker that came with it.
 *
 * A run that produced figures is COMPLETED even when the engine returned warnings:
 * the warnings qualify how its numbers are read, they do not make the analysis
 * unfinished. They are still stored on the run (`flags`) and shown in a warning box
 * on its page — nothing is lost here except a status that, because the engine flags
 * something on almost every multi-source run, had come to mean nothing.
 *
 * Existing rows are converted rather than left behind: leaving 'needs_review' in the
 * column with no matching enum case would make Doctrine throw on hydrating them.
 */
final class Version20260804004500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the needs_review status and the reviewed_at/reviewed_by marker';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE analysis_run SET status = 'completed' WHERE status = 'needs_review'");

        $this->addSql('ALTER TABLE analysis_run DROP FOREIGN KEY FK_8DBDA06EFC6B21F1');
        $this->addSql('DROP INDEX IDX_8DBDA06EFC6B21F1 ON analysis_run');
        $this->addSql('ALTER TABLE analysis_run DROP reviewed_at, DROP reviewed_by_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run ADD reviewed_at DATETIME DEFAULT NULL, ADD reviewed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analysis_run ADD CONSTRAINT FK_8DBDA06EFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8DBDA06EFC6B21F1 ON analysis_run (reviewed_by_id)');
        // Which runs were 'needs_review' is not recoverable: the flags are still on
        // each run, so the distinction can be rebuilt from them if it is ever wanted.
    }
}
