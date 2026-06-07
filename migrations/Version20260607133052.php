<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607133052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute User.isBanned (suspension de compte par un administrateur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD is_banned BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN is_banned DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP is_banned');
    }
}
