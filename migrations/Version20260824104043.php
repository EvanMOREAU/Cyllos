<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a client have several HelloAsso forms (e.g. one for "Particuliers",
 * one for "Professionnels") instead of exactly one: hello_asso_config goes
 * from a 1-1 to a 1-N relation with client, and payment gains a direct link
 * to the config it came from — needed because a payment can be manually
 * (re)credited long after the fact, by which point there's no other way to
 * tell which of the client's forms/credentials it belongs to.
 *
 * Purely additive for existing clients: each keeps exactly the one
 * hello_asso_config row it already had, backfilled with a default label and
 * linked to its existing payments, so behaviour is unchanged until a second
 * form is actually added through the admin.
 */
final class Version20260824104043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HelloAssoConfig becomes a 1-N relation on Client (label + active), and Payment gains a required link to the config it was created from.';
    }

    public function up(Schema $schema): void
    {
        // hello_asso_config: 1-1 -> 1-N, with a label/active pair per form.
        // The DEFAULT values below only backfill existing rows; both are
        // dropped again at the end so the column definitions end up matching
        // the entity mapping exactly (no lingering DB-level default).
        $this->addSql(<<<'SQL'
            ALTER TABLE
              hello_asso_config
            DROP
              INDEX UNIQ_2599AC4C19EB6921,
            ADD
              INDEX IDX_2599AC4C19EB6921 (client_id)
        SQL);
        $this->addSql("ALTER TABLE hello_asso_config ADD label VARCHAR(100) NOT NULL DEFAULT 'Principal', ADD active TINYINT NOT NULL DEFAULT 1");
        $this->addSql('CREATE UNIQUE INDEX client_helloasso_form_unique ON hello_asso_config (client_id, form_slug)');
        $this->addSql('ALTER TABLE hello_asso_config ALTER label DROP DEFAULT, ALTER active DROP DEFAULT');

        // payment.hello_asso_config_id: added nullable, backfilled from each
        // payment's client (deterministic today, since every client still
        // has exactly one config at this point in the migration), then
        // tightened to NOT NULL.
        $this->addSql('ALTER TABLE payment ADD hello_asso_config_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE payment p
            INNER JOIN hello_asso_config c ON c.client_id = p.client_id
            SET p.hello_asso_config_id = c.id
            WHERE p.hello_asso_config_id IS NULL
        SQL);
        $this->addSql('ALTER TABLE payment MODIFY hello_asso_config_id INT NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              payment
            ADD
              CONSTRAINT FK_6D28840D94A069B4 FOREIGN KEY (hello_asso_config_id) REFERENCES hello_asso_config (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_6D28840D94A069B4 ON payment (hello_asso_config_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D94A069B4');
        $this->addSql('DROP INDEX IDX_6D28840D94A069B4 ON payment');
        $this->addSql('ALTER TABLE payment DROP hello_asso_config_id');

        $this->addSql(<<<'SQL'
            ALTER TABLE
              hello_asso_config
            DROP
              INDEX IDX_2599AC4C19EB6921,
            ADD
              UNIQUE INDEX UNIQ_2599AC4C19EB6921 (client_id)
        SQL);
        $this->addSql('DROP INDEX client_helloasso_form_unique ON hello_asso_config');
        $this->addSql('ALTER TABLE hello_asso_config DROP label, DROP active');
    }
}
