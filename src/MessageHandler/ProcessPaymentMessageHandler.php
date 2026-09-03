<?php

namespace App\MessageHandler;

use App\Message\ProcessPaymentMessage;
use App\Payment\PaymentProcessor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessPaymentMessageHandler
{
    public function __construct(
        private PaymentProcessor $paymentProcessor,
    ) {
    }

    public function __invoke(ProcessPaymentMessage $message): void
    {
        $this->paymentProcessor->processQueuedPayment($message->paymentId, $message->reportedWaiting);
    }
}
