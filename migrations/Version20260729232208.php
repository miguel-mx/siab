<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729232208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analysis_run (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(255) NOT NULL, sources VARCHAR(120) NOT NULL, report_language VARCHAR(2) NOT NULL, run_timestamp VARCHAR(32) DEFAULT NULL, total_articles INT DEFAULT 0 NOT NULL, total_type_a INT DEFAULT 0 NOT NULL, total_type_b INT DEFAULT 0 NOT NULL, total_self INT DEFAULT 0 NOT NULL, flags JSON NOT NULL, raw_snapshot JSON DEFAULT NULL, report LONGTEXT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, researcher_id INT NOT NULL, owner_id INT DEFAULT NULL, INDEX IDX_8DBDA06EC7533BDE (researcher_id), INDEX IDX_8DBDA06E7E3C61F9 (owner_id), INDEX idx_run_status (status), INDEX idx_run_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, openalex_id VARCHAR(64) DEFAULT NULL, doi VARCHAR(255) DEFAULT NULL, title LONGTEXT NOT NULL, year INT DEFAULT NULL, journal VARCHAR(255) DEFAULT NULL, authors LONGTEXT DEFAULT NULL, openalex_cited_by_count INT DEFAULT 0 NOT NULL, scopus_cited_by_count INT DEFAULT NULL, wos_cited_by_count INT DEFAULT NULL, zbmath_cited_by_count INT DEFAULT NULL, inspire_cited_by_count INT DEFAULT NULL, cites_type_a INT DEFAULT 0 NOT NULL, cites_type_b INT DEFAULT 0 NOT NULL, cites_self INT DEFAULT 0 NOT NULL, analysis_run_id INT NOT NULL, INDEX idx_article_run (analysis_run_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE citing_work (id INT AUTO_INCREMENT NOT NULL, openalex_id VARCHAR(64) DEFAULT NULL, doi VARCHAR(255) DEFAULT NULL, title LONGTEXT NOT NULL, year INT DEFAULT NULL, authors JSON NOT NULL, source VARCHAR(32) DEFAULT \'openalex\' NOT NULL, classification VARCHAR(8) DEFAULT NULL, article_id INT NOT NULL, INDEX idx_citing_article (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE researcher (id INT AUTO_INCREMENT NOT NULL, display_name VARCHAR(255) NOT NULL, field VARCHAR(255) DEFAULT NULL, orcid VARCHAR(19) DEFAULT NULL, openalex_id VARCHAR(32) DEFAULT NULL, scopus_id VARCHAR(32) DEFAULT NULL, zbmath_code VARCHAR(64) DEFAULT NULL, inspire_recid VARCHAR(32) DEFAULT NULL, works_count INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_researcher_orcid (orcid), UNIQUE INDEX uniq_researcher_openalex (openalex_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, display_name VARCHAR(120) DEFAULT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE analysis_run ADD CONSTRAINT FK_8DBDA06EC7533BDE FOREIGN KEY (researcher_id) REFERENCES researcher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE analysis_run ADD CONSTRAINT FK_8DBDA06E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E663FDF504F FOREIGN KEY (analysis_run_id) REFERENCES analysis_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE citing_work ADD CONSTRAINT FK_EB99A0FA7294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analysis_run DROP FOREIGN KEY FK_8DBDA06EC7533BDE');
        $this->addSql('ALTER TABLE analysis_run DROP FOREIGN KEY FK_8DBDA06E7E3C61F9');
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E663FDF504F');
        $this->addSql('ALTER TABLE citing_work DROP FOREIGN KEY FK_EB99A0FA7294869C');
        $this->addSql('DROP TABLE analysis_run');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE citing_work');
        $this->addSql('DROP TABLE researcher');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
