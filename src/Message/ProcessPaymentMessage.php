<?php

namespace App\Message;

/**
 * Dispatched by the HelloAsso webhook once a Payment has been persisted as "todo",
 * so the Cyclos credit attempt and its success/failure notifications happen in a
 * worker instead of inside the webhook request (HelloAsso times the webhook out
 * and retries it otherwise).
 *
 * Carries only the payment id (the worker reloads a managed entity) and the
 * HelloAsso "Waiting" flag, which isn't stored on Payment but changes the
 * automatic decision.
 */
final readonly class ProcessPaymentMessage
{
    public function __construct(
        public int $paymentId,
        public bool $reportedWaiting = false,
    ) {
    }
}
