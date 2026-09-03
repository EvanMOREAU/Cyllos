<?php

namespace App\Notification;

use App\Entity\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends plain-text operational emails (late payment, failure, success...) to a
 * client's configured recipient(s). Subject and body are produced by
 * EmailComposer, which applies the client's ClientCustomization (template
 * overrides, subject prefix, footer) on top of the global defaults.
 */
class NotificationMailer
{
    private const RECIPIENT_DELIMITER = ',';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly EmailComposer $composer,
        private readonly string $fromAddress,
    ) {
    }

    /**
     * Composes the "$type" notification for $client (applying any
     * per-client customization) and sends it to $recipients.
     *
     * @param array<string, string|int|float> $params values for the %placeholders%
     */
    public function sendForClient(Client $client, string $recipients, string $type, array $params = []): void
    {
        $composed = $this->composer->compose($client, $type, $params);

        $this->send($recipients, $composed->subject, $composed->body);
    }

    public function send(string $recipients, string $subject, string $body): void
    {
        $addresses = array_filter(array_map('trim', explode(self::RECIPIENT_DELIMITER, $recipients)));
        if ($addresses === []) {
            $this->logger->warning('No mail recipient configured, skipping notification "{subject}"', ['subject' => $subject]);

            return;
        }

        $email = (new Email())
            ->from($this->fromAddress)
            ->to(...$addresses)
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Error sending email: {message}', ['message' => $exception->getMessage()]);
        }
    }
}
