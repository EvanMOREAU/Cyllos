<?php

namespace App\Message;

/**
 * One HelloAsso catch-up fetch for a single client, run on the async queue.
 *
 * Two producers, both with $attemptAutomaticCredit = true so a discovered
 * payment is credited on Cyclos straight away (subject to the shared
 * automatic-credit decision — client setting + accepted delay) rather than
 * waiting in "todo" for a manual review:
 *  - the periodic `app:helloasso:fetch`, which fans out one per active client
 *    (with a small random delay) so a slow/failing client doesn't hold up the
 *    others;
 *  - the admin "Synchro HelloAsso" button, off the request thread.
 *
 * Pass $attemptAutomaticCredit = false explicitly for a record-only sweep that
 * must never move money.
 */
final readonly class FetchClientPaymentsMessage
{
    public function __construct(
        public int $clientId,
        public bool $attemptAutomaticCredit = false,
    ) {
    }
}
