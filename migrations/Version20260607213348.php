<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607213348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute stripe_customer_id sur artisan (étape 8 : abonnements Stripe)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artisan ADD stripe_customer_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artisan DROP stripe_customer_id');
    }
}
