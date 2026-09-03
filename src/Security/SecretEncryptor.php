<?php

namespace App\Security;

/**
 * Encrypts secrets (HelloAsso client secret, Cyclos password) at rest using
 * AES-256-GCM.
 *
 * Key rotation: new values are always encrypted with the primary key
 * (APP_ENCRYPTION_KEY). Decryption tries the primary key first, then each
 * decrypt-only legacy key (APP_ENCRYPTION_KEYS_LEGACY, comma-separated) — so a
 * key can be rotated without downtime, and `app:secrets:reencrypt` then rewrites
 * every stored secret with the new primary key, after which the legacy key can
 * be dropped.
 */
class SecretEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** @var non-empty-list<string> primary key first, then decrypt-only legacy keys */
    private array $keys;

    public function __construct(
        #[\SensitiveParameter] string $encryptionKey,
        #[\SensitiveParameter] string $legacyKeys = '',
    ) {
        $this->keys = [$this->decodeKey($encryptionKey, 'APP_ENCRYPTION_KEY')];

        foreach (array_filter(array_map('trim', explode(',', $legacyKeys))) as $legacy) {
            $this->keys[] = $this->decodeKey($legacy, 'APP_ENCRYPTION_KEYS_LEGACY');
        }
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $this->keys[0], OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('Failed to encrypt secret.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || \strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipherText = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        foreach ($this->keys as $key) {
            $plainText = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plainText !== false) {
                return $plainText;
            }
        }

        throw new \RuntimeException('Failed to decrypt secret: payload authentication failed (no configured key matched).');
    }

    /**
     * Whether $encoded can be decrypted with the primary key alone — i.e. it does
     * not need re-encryption after a rotation. Used by app:secrets:reencrypt.
     */
    public function isEncryptedWithPrimaryKey(string $encoded): bool
    {
        if ($encoded === '') {
            return true;
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || \strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            return false;
        }

        return openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->keys[0],
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
        ) !== false;
    }

    private function decodeKey(#[\SensitiveParameter] string $encoded, string $envName): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || \strlen($decoded) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a base64-encoded %d-byte key. Generate one with: php bin/console app:generate-encryption-key',
                $envName,
                self::KEY_BYTES,
            ));
        }

        return $decoded;
    }
}
