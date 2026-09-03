<?php

namespace App\Notification;

use App\Entity\Client;
use App\Entity\ClientCustomization;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a notification "type" (over_limit, too_late, success...) plus a bag of
 * values into a ready-to-send subject and body, applying the client's
 * ClientCustomization when there is one.
 *
 * Resolution order for both subject and body:
 *   1. the client's override text for this type, if set;
 *   2. otherwise the global default from the "emails" translation domain
 *      (translations/emails.fr.yaml).
 *
 * Placeholders are then substituted with a plain str_tr() over a fixed
 * whitelist (ClientCustomization::PLACEHOLDERS) — never a template engine, so
 * an admin-authored template can't execute anything. A placeholder with no
 * supplied value is left as literal text rather than blanked.
 *
 * Finally the subject prefix ("[Cyllos]" unless the client overrides it) and,
 * if set, the client's footer are applied.
 */
class EmailComposer
{
    public const DEFAULT_SUBJECT_PREFIX = '[Cyllos]';
    public const DEFAULT_PREVIEW_MODE_LABEL = 'aperçu (non crédité)';
    public const REAL_MODE_LABEL = 'réel (crédité)';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string|int|float> $params values for the %placeholders%,
     *                                                 keyed with the surrounding percent signs
     */
    public function compose(Client $client, string $type, array $params = []): ComposedEmail
    {
        $customization = $client->getCustomization();

        $subject = $customization?->getEmailSubjectOverride($type)
            ?? $this->translator->trans($type . '.subject', [], 'emails');
        $body = $customization?->getEmailBodyOverride($type)
            ?? $this->translator->trans($type . '.body', [], 'emails');

        $replacements = [];
        foreach ($params as $token => $value) {
            $replacements[$token] = (string) $value;
        }

        $subject = strtr($subject, $replacements);
        $body = strtr($body, $replacements);

        return new ComposedEmail(
            $this->applySubjectPrefix($subject, $customization),
            $this->applyFooter($body, $customization),
        );
    }

    private function applySubjectPrefix(string $subject, ?ClientCustomization $customization): string
    {
        $prefix = trim($customization?->getEmailSubjectPrefix() ?? self::DEFAULT_SUBJECT_PREFIX);

        if ($prefix === '' || str_starts_with($subject, $prefix . ' ')) {
            return $subject;
        }

        return $prefix . ' ' . $subject;
    }

    private function applyFooter(string $body, ?ClientCustomization $customization): string
    {
        $footer = trim($customization?->getEmailFooter() ?? '');

        return $footer === '' ? $body : rtrim($body) . "\n\n" . $footer;
    }
}
