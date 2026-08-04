<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730001310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Existing rows need a value before the unique index can exist, and a
        // transliterating slug ("Hrušák" → "hrusak") is PHP's job, not SQL's: rows
        // get an id-based placeholder here and `app:slugs:backfill` rewrites them.
        $this->addSql("ALTER TABLE analysis_run ADD slug VARCHAR(160) DEFAULT '' NOT NULL");
        $this->addSql("UPDATE analysis_run SET slug = CONCAT('analisis-', id)");
        $this->addSql('ALTER TABLE analysis_run ALTER COLUMN slug DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8DBDA06E989D9B62 ON analysis_run (slug)');

        $this->addSql("ALTER TABLE researcher ADD slug VARCHAR(140) DEFAULT '' NOT NULL");
        $this->addSql("UPDATE researcher SET slug = CONCAT('investigador-', id)");
        $this->addSql('ALTER TABLE researcher ALTER COLUMN slug DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_75FF2EDE989D9B62 ON researcher (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_8DBDA06E989D9B62 ON analysis_run');
        $this->addSql('ALTER TABLE analysis_run DROP slug');
        $this->addSql('DROP INDEX UNIQ_75FF2EDE989D9B62 ON researcher');
        $this->addSql('ALTER TABLE researcher DROP slug');
    }
}
