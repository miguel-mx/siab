<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit trail: who changed what, and when — for accounts and for analyses.
 *
 * Append-only by intent. The subject is a type/id pair plus the label it had at
 * the time, not a foreign key, so a deleted analysis still reads as "Análisis #9
 * (Michael Hrušák)" once its row is gone. The actor keeps a real relation, since
 * accounts are never deleted; ON DELETE SET NULL is belt and braces.
 */
final class Version20260803185713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log (account and analysis changes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(40) NOT NULL, actor_label VARCHAR(190) NOT NULL, subject_type VARCHAR(20) NOT NULL, subject_id INT DEFAULT NULL, subject_label VARCHAR(255) NOT NULL, details JSON DEFAULT NULL, occurred_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_F6E1C0F510DAF24A (actor_id), INDEX idx_audit_occurred (occurred_at), INDEX idx_audit_subject (subject_type, subject_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F510DAF24A');
        $this->addSql('DROP TABLE audit_log');
    }
}
