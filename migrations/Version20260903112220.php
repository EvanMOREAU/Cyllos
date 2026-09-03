<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds client.legacy_webhook_last_seen_at: when HelloAsso last called the
 * token-less webhook URL for this client. Surfaced on the admin dashboard so a
 * still-unmigrated notification URL is visible. Nullable, no backfill.
 */
final class Version20260903112220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client.legacy_webhook_last_seen_at (dashboard signal for un-migrated webhook URLs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD legacy_webhook_last_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP legacy_webhook_last_seen_at');
    }
}
