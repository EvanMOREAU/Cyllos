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
