<?php

namespace App\MessageHandler;

use App\Message\FetchClientPaymentsMessage;
use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchClientPaymentsMessageHandler
{
    public function __construct(
        private ClientRepository $clientRepository,
        private PaymentProcessor $paymentProcessor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchClientPaymentsMessage $message): void
    {
        $client = $this->clientRepository->find($message->clientId);

        if ($client === null || !$client->isActive()) {
            $this->logger->info('FetchClientPaymentsMessage: client {id} is gone or inactive, skipping', ['id' => $message->clientId]);

            return;
        }

        $added = $this->paymentProcessor->fetchMissingPayments($client, $message->attemptAutomaticCredit);

        if ($added > 0) {
            $this->logger->info('HelloAsso fetch: {count} new payment(s) for client {slug} (auto-credit: {auto})', [
                'count' => $added,
                'slug' => $client->getSlug(),
                'auto' => $message->attemptAutomaticCredit ? 'yes' : 'no',
            ]);
        }
    }
}
