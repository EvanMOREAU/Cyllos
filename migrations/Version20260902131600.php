<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds client.webhook_token: a per-client shared secret embedded in the HelloAsso
 * webhook URL (/webhook/helloasso/{slug}/{token}). HelloAsso does not sign its
 * notifications, so without this anyone who guesses a client slug can POST forged
 * payments.
 *
 * The column is added nullable, backfilled with one fresh random token per
 * existing client, then tightened to NOT NULL + UNIQUE — matching the entity
 * mapping. After deploying, each existing client's notification URL must be
 * updated in HelloAsso to include its new token (visible on the admin client
 * page); until then the periodic `app:helloasso:fetch` safety net keeps
 * recording their payments.
 */
final class Version20260902131600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client.webhook_token (per-client secret for the HelloAsso webhook URL).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD webhook_token VARCHAR(64) DEFAULT NULL');

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM client') as $id) {
            $this->addSql(
                'UPDATE client SET webhook_token = :token WHERE id = :id',
                ['token' => bin2hex(random_bytes(32)), 'id' => $id],
            );
        }

        $this->addSql('ALTER TABLE client MODIFY webhook_token VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C7440455D5E74442 ON client (webhook_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C7440455D5E74442 ON client');
        $this->addSql('ALTER TABLE client DROP webhook_token');
    }
}
