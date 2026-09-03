<?php

namespace App\Notification;

/**
 * A fully-resolved notification: subject and plain-text body after the
 * per-client template, placeholders, subject prefix and footer have been
 * applied by EmailComposer. Ready to hand to NotificationMailer::send().
 */
final readonly class ComposedEmail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
