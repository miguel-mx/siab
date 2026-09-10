<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The work type behind the "excluir preprints" filter on a run's article list.
 *
 * `work_type` is the engine's normalised OpenAlex type ('article', 'preprint',
 * 'book-chapter'…) and `repository` names the preprint server (arXiv, bioRxiv), so
 * what the filter hides can be reported as "17 arXiv, 1 bioRxiv" rather than only
 * as a smaller number.
 *
 * Existing rows keep NULL in both, and NULL means *unclassified*, not "not a
 * preprint": the filter leaves those rows visible on purpose, since the same NULL
 * arrives on every record that reached us from Scopus, WoS, zbMATH or INSPIRE,
 * none of which report a type. The practical consequence is that runs analysed
 * before this migration show nothing to exclude until they are run again.
 *
 * The index carries analysis_run_id first because the filter is always applied
 * within one run; work_type alone would never be selective enough to be used.
 */
final class Version20260904163508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record each article\'s work type and repository, for the preprint filter';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD work_type VARCHAR(32) DEFAULT NULL, ADD repository VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_article_run_type ON article (analysis_run_id, work_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_article_run_type ON article');
        $this->addSql('ALTER TABLE article DROP work_type, DROP repository');
    }
}
