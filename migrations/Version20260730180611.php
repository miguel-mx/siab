<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260730180611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.active so accounts can be deactivated instead of deleted.';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 1 is load-bearing: without it every existing row would land on 0
        // and the UserChecker would lock out all current accounts, this one included.
        // `user` needs the backticks — it is a reserved word in MySQL 8.
        $this->addSql('ALTER TABLE `user` ADD active TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP active');
    }
}
