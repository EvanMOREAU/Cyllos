<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds client_customization: optional per-client overrides for the wording of
 * the operational e-mails Cyllos sends (JSON template map + subject prefix +
 * footer), the Cyclos transaction description prefix, and the "preview mode"
 * label. One nullable row per client; a client without a row keeps the
 * application defaults everywhere, so nothing needs backfilling.
 */
final class Version20260903110856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client_customization (per-client e-mail templates and cosmetic overrides).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE client_customization (id INT AUTO_INCREMENT NOT NULL, email_templates JSON NOT NULL, email_subject_prefix VARCHAR(100) DEFAULT NULL, email_footer LONGTEXT DEFAULT NULL, cyclos_description_prefix VARCHAR(255) DEFAULT NULL, preview_mode_label VARCHAR(255) DEFAULT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_DCB553F219EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client_customization ADD CONSTRAINT FK_DCB553F219EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_customization DROP FOREIGN KEY FK_DCB553F219EB6921');
        $this->addSql('DROP TABLE client_customization');
    }
}
