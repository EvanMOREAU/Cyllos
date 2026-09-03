<?php

namespace App\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Read-only view of the Doctrine-backed Messenger queue (table messenger_messages),
 * shared by the /health probe and the admin dashboard. "Pending" is everything not
 * yet delivered on a queue other than "failed"; "failed" is the dead-letter queue.
 */
final readonly class QueueStatus
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function pending(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name != 'failed' AND delivered_at IS NULL",
        );
    }

    public function failed(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'",
        );
    }

    /**
     * The most recent dead-lettered messages, decoded just enough for the admin
     * dashboard: short message name, when it landed in "failed", and the tail of
     * the exception message when the transport recorded one. Best-effort — a row
     * whose headers can't be parsed still appears, with nulls.
     *
     * @return list<array{id: int, message: string, failedAt: ?string, error: ?string}>
     */
    public function failedMessages(int $limit = 8): array
    {
        $limit = max(1, min($limit, 50));

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, headers, created_at FROM messenger_messages WHERE queue_name = 'failed' ORDER BY id DESC LIMIT " . $limit,
        );

        return array_map(static function (array $row): array {
            $headers = json_decode((string) ($row['headers'] ?? ''), true);
            $headers = \is_array($headers) ? $headers : [];

            $type = \is_string($headers['type'] ?? null) ? $headers['type'] : null;
            $message = $type !== null && str_contains($type, '\\')
                ? substr((string) strrchr($type, '\\'), 1)
                : ($type ?? 'message');

            $error = null;
            foreach ($headers as $key => $value) {
                if (!\is_string($value) || !str_contains((string) $key, 'ErrorDetailsStamp')) {
                    continue;
                }
                $decoded = json_decode($value, true);
                $candidate = \is_array($decoded) ? ($decoded[0]['message'] ?? $decoded['message'] ?? null) : null;
                if (\is_string($candidate) && $candidate !== '') {
                    $error = mb_strlen($candidate) > 200 ? mb_substr($candidate, 0, 200) . '…' : $candidate;
                }
                break;
            }

            return [
                'id' => (int) $row['id'],
                'message' => $message,
                'failedAt' => \is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
                'error' => $error,
            ];
        }, $rows);
    }

    /**
     * Seconds the oldest already-available pending message has been waiting, or
     * null when nothing is waiting. A steadily rising value means the worker is
     * not keeping up (or is down).
     */
    public function oldestPendingSeconds(): ?int
    {
        $oldest = $this->connection->fetchOne(
            "SELECT MIN(available_at) FROM messenger_messages
             WHERE queue_name != 'failed' AND delivered_at IS NULL AND available_at <= UTC_TIMESTAMP()",
        );

        if (!\is_string($oldest)) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        return max(0, (new \DateTimeImmutable('now', $utc))->getTimestamp() - (new \DateTimeImmutable($oldest, $utc))->getTimestamp());
    }
}
