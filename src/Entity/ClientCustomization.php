<?php

namespace App\Entity;

use App\Repository\ClientCustomizationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-client cosmetic overrides that must never be hard-coded per client:
 * the wording of the operational e-mails Cyllos sends, and a few strings that
 * leak into external systems (the Cyclos transaction description, the label
 * shown for "preview" mode).
 *
 * Everything here is optional. A null field means "use the application
 * default" — the global text in translations/emails.fr.yaml for e-mails, and
 * CyclosClient::PAYMENT_DESCRIPTION_PREFIX for the Cyclos description. A client
 * with no ClientCustomization row at all therefore behaves exactly as before
 * this entity existed; see EmailComposer for the resolution order.
 *
 * Admin-only on purpose: unlike ClientSetting (which the client edits from
 * /settings), a broken template here would silently degrade every
 * notification, so editing stays behind ROLE_ADMIN on the client sheet.
 */
#[ORM\Entity(repositoryClass: ClientCustomizationRepository::class)]
class ClientCustomization
{
    /**
     * E-mail types that can be overridden — the keys accepted in
     * $emailTemplates and the translation ids (minus ".subject"/".body")
     * used as the fallback. Kept in sync with translations/emails.fr.yaml.
     *
     * @var list<string>
     */
    public const EMAIL_TYPES = [
        'over_limit',
        'too_late',
        'waiting',
        'success',
        'failure',
        'manual_error',
    ];

    /**
     * Human labels for EMAIL_TYPES, shown in the admin editor. "(interne)"
     * marks the ones sent to ClientSetting::mailRecipient (Cylaos ops); the
     * others go to the client's own contact address.
     *
     * @var array<string, string>
     */
    public const EMAIL_TYPE_LABELS = [
        'over_limit' => 'Paiement dépassant la limite (interne)',
        'too_late' => 'Paiement reçu en retard (interne)',
        'waiting' => 'Paiement en attente (interne)',
        'success' => 'Paiement réussi (au client)',
        'failure' => 'Paiement en échec (au client)',
        'manual_error' => 'Erreur lors d’un crédit manuel (interne)',
    ];

    /**
     * Placeholders allowed in a template subject/body. Any %token% outside
     * this list is rejected on save (see ClientCustomizationType). Not every
     * placeholder is filled for every e-mail type — an unfilled one is left
     * untouched rather than blanked, so a template that uses %amount% in
     * "manual_error" simply renders the literal text.
     *
     * @var array<string, string> token => human description (shown as form help)
     */
    public const PLACEHOLDERS = [
        '%id%' => 'Identifiant HelloAsso du paiement',
        '%amount%' => 'Montant, déjà formaté (ex. 12.34)',
        '%payer%' => 'Prénom et nom du payeur',
        '%payer_email%' => 'E-mail du payeur tel que fourni par HelloAsso',
        '%form%' => 'Libellé du formulaire HelloAsso',
        '%date%' => 'Date du paiement (JJ/MM/AAAA)',
        '%client%' => 'Nom du client',
        '%mode%' => 'Libellé du mode de crédit (réel ou aperçu)',
        '%errors%' => 'Liste des erreurs (e-mail « erreur de traitement » uniquement)',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'customization', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Client $client = null;

    /**
     * Sparse map of overrides: { "<type>": { "subject"?: string, "body"?: string } }.
     * Only the types/fields the admin actually changed are stored; anything
     * absent falls back to the "emails" translation domain.
     *
     * @var array<string, array{subject?: string, body?: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $emailTemplates = [];

    /**
     * Replaces the default "[Cyllos]" prefix in every e-mail subject for this
     * client. Null keeps "[Cyllos]"; an empty string means "no prefix".
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $emailSubjectPrefix = null;

    /**
     * Free text appended (after a blank line) to the body of every e-mail for
     * this client — signature, legal mention, support contact...
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $emailFooter = null;

    /**
     * Overrides CyclosClient::PAYMENT_DESCRIPTION_PREFIX for this client. The
     * HelloAsso payment id is still appended, so "Recharge instantanée " gives
     * "Recharge instantanée 92304013". Changing this is handled as a
     * transition by PaymentProcessor: the duplicate check looks for both the
     * old and the new description so a re-credit across the change can't
     * double-credit.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $cyclosDescriptionPrefix = null;

    /**
     * Wording shown for the "%mode%" placeholder (and on the client sheet)
     * when Cyclos payments are disabled and credits run in preview only.
     * Null uses the default "aperçu (non crédité)".
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $previewModeLabel = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return array<string, array{subject?: string, body?: string}>
     */
    public function getEmailTemplates(): array
    {
        return $this->emailTemplates;
    }

    /**
     * @param array<string, array{subject?: string, body?: string}> $emailTemplates
     */
    public function setEmailTemplates(array $emailTemplates): static
    {
        $this->emailTemplates = $emailTemplates;

        return $this;
    }

    public function getEmailSubjectOverride(string $type): ?string
    {
        $value = $this->emailTemplates[$type]['subject'] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function getEmailBodyOverride(string $type): ?string
    {
        $value = $this->emailTemplates[$type]['body'] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function getEmailSubjectPrefix(): ?string
    {
        return $this->emailSubjectPrefix;
    }

    public function setEmailSubjectPrefix(?string $emailSubjectPrefix): static
    {
        $this->emailSubjectPrefix = $emailSubjectPrefix;

        return $this;
    }

    public function getEmailFooter(): ?string
    {
        return $this->emailFooter;
    }

    public function setEmailFooter(?string $emailFooter): static
    {
        $this->emailFooter = $emailFooter;

        return $this;
    }

    public function getCyclosDescriptionPrefix(): ?string
    {
        return $this->cyclosDescriptionPrefix;
    }

    public function setCyclosDescriptionPrefix(?string $cyclosDescriptionPrefix): static
    {
        $this->cyclosDescriptionPrefix = $cyclosDescriptionPrefix;

        return $this;
    }

    public function getPreviewModeLabel(): ?string
    {
        return $this->previewModeLabel;
    }

    public function setPreviewModeLabel(?string $previewModeLabel): static
    {
        $this->previewModeLabel = $previewModeLabel;

        return $this;
    }
}
