<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Somebody has read the warnings on this run", as a mark of its own.
 *
 * Deliberately not a status change: the run stays NEEDS_REVIEW, because its
 * warnings are part of how the figures must be read and do not stop applying once
 * a person has seen them. Confirming you looked and pretending the caveats are
 * gone are two different claims.
 */
final class Version20260803234238 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add analysis_run.reviewed_at / reviewed_by';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run ADD reviewed_at DATETIME DEFAULT NULL, ADD reviewed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analysis_run ADD CONSTRAINT FK_8DBDA06EFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8DBDA06EFC6B21F1 ON analysis_run (reviewed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run DROP FOREIGN KEY FK_8DBDA06EFC6B21F1');
        $this->addSql('DROP INDEX IDX_8DBDA06EFC6B21F1 ON analysis_run');
        $this->addSql('ALTER TABLE analysis_run DROP reviewed_at, DROP reviewed_by_id');
    }
}
