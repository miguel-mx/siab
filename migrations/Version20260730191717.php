<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730191717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store each article\'s Scopus/WoS/zbMATH/INSPIRE record ids alongside the counts.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD scopus_id VARCHAR(32) DEFAULT NULL, ADD scopus_eid VARCHAR(64) DEFAULT NULL, ADD wos_uid VARCHAR(64) DEFAULT NULL, ADD zbmath_id VARCHAR(32) DEFAULT NULL, ADD inspire_recid VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP scopus_id, DROP scopus_eid, DROP wos_uid, DROP zbmath_id, DROP inspire_recid');
    }
}
