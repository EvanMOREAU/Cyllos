<?php

namespace App\Message;

/**
 * One HelloAsso catch-up fetch for a single client, run on the async queue.
 *
 * Two producers:
 *  - the periodic `app:helloasso:fetch`, which fans out one per active client
 *    (with a small random delay) so a slow/failing client doesn't hold up the
 *    others — safety-net semantics: missed payments are recorded as "todo",
 *    never auto-credited ($attemptAutomaticCredit = false);
 *  - the admin "Synchro HelloAsso" button, which sets $attemptAutomaticCredit
 *    to true so each discovered payment runs through the same automatic-credit
 *    decision as a real-time webhook, off the request thread.
 */
final readonly class FetchClientPaymentsMessage
{
    public function __construct(
        public int $clientId,
        public bool $attemptAutomaticCredit = false,
    ) {
    }
}
