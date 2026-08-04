<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730181511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the setting table backing /admin/configuracion (secrets stored encrypted).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_9F74B898896DBBDE (updated_by_id), PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE setting ADD CONSTRAINT FK_9F74B898896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE setting DROP FOREIGN KEY FK_9F74B898896DBBDE');
        $this->addSql('DROP TABLE setting');
    }
}
