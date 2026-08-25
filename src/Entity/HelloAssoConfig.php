<?php

namespace App\Entity;

use App\Repository\HelloAssoConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: HelloAssoConfigRepository::class)]
#[ORM\UniqueConstraint(name: 'client_helloasso_form_unique', columns: ['client_id', 'form_slug'])]
class HelloAssoConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'helloAssoConfigs', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    /**
     * Free-form admin-facing name distinguishing this config from a client's
     * other HelloAsso forms (e.g. "Particuliers" / "Professionnels").
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $label = '';

    /**
     * Disabling keeps the config (and the payment history that references
     * it) around while rejecting new webhooks/fetches for it — used instead
     * of deletion, since a config with payment history can't be deleted
     * (see the Payment::$helloAssoConfig FK).
     */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $apiUrl = 'https://api.helloasso.com/';

    /**
     * HelloAsso's OAuth2 client_id credential (not to be confused with our Client entity relation).
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $helloAssoClientId = '';

    /**
     * Stored encrypted at rest, see SecretEncryptor.
     */
    #[ORM\Column(type: 'text')]
    private string $clientSecretEncrypted = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $organizationSlug = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $formSlug = '';

    /**
     * HelloAsso form type (CrowdFunding, PaymentForm, Membership, Event,
     * Donation, Shop...), used to build the payment history fetch URL. Must
     * match the actual campaign type in HelloAsso, or the catch-up fetch
     * (/v5/organizations/{org}/forms/{formType}/{formSlug}/payments) will
     * silently return no results.
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $formType = 'PaymentForm';

    #[ORM\Column]
    #[Assert\Positive]
    private int $maxAmount = 250;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $extraMailFieldName = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $fetchNbDays = 5;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function setApiUrl(string $apiUrl): static
    {
        $this->apiUrl = rtrim($apiUrl, '/') . '/';

        return $this;
    }

    public function getHelloAssoClientId(): string
    {
        return $this->helloAssoClientId;
    }

    public function setHelloAssoClientId(string $helloAssoClientId): static
    {
        $this->helloAssoClientId = $helloAssoClientId;

        return $this;
    }

    public function getClientSecretEncrypted(): string
    {
        return $this->clientSecretEncrypted;
    }

    public function setClientSecretEncrypted(string $clientSecretEncrypted): static
    {
        $this->clientSecretEncrypted = $clientSecretEncrypted;

        return $this;
    }

    public function getOrganizationSlug(): string
    {
        return $this->organizationSlug;
    }

    public function setOrganizationSlug(string $organizationSlug): static
    {
        $this->organizationSlug = $organizationSlug;

        return $this;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    public function setFormSlug(string $formSlug): static
    {
        $this->formSlug = $formSlug;

        return $this;
    }

    public function getFormType(): string
    {
        return $this->formType;
    }

    public function setFormType(string $formType): static
    {
        $this->formType = $formType;

        return $this;
    }

    public function getMaxAmount(): int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(int $maxAmount): static
    {
        $this->maxAmount = $maxAmount;

        return $this;
    }

    public function getExtraMailFieldName(): ?string
    {
        return $this->extraMailFieldName;
    }

    public function setExtraMailFieldName(?string $extraMailFieldName): static
    {
        $this->extraMailFieldName = $extraMailFieldName;

        return $this;
    }

    public function getFetchNbDays(): int
    {
        return $this->fetchNbDays;
    }

    public function setFetchNbDays(int $fetchNbDays): static
    {
        $this->fetchNbDays = $fetchNbDays;

        return $this;
    }
}
