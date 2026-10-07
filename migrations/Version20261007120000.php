<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which rule a run's A/B/self figures were classified under.
 *
 * Type B used to mean "a co-author of any of the researcher's works signs the
 * citing work"; it now means "an author of the cited work does", as Rizoma defines
 * it. The engine stamps its rule on every result and the run keeps it, so the
 * report never compares figures across the change and the run's page can say when
 * its figures follow the earlier rule.
 *
 * Existing rows stay NULL, and NULL means *the earlier rule*: every run before this
 * migration was classified that way. Nothing is backfilled — their per-article
 * counts came from the engine and cannot be recomputed here without re-running.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the A/B/self classification rule each run was computed under';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run ADD classification_rule VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_run DROP classification_rule');
    }
}
