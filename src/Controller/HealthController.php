<?php

namespace App\Controller;

use App\Monitoring\QueueStatus;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unauthenticated liveness/readiness probe for external monitoring. Reports on
 * Cyllos's own moving parts — database, the async message queue, and payments
 * stuck in "todo" (the tell-tale of a stopped worker) — but deliberately does NOT
 * call HelloAsso or Cyclos: that would hammer their APIs (and need a client's
 * OAuth credentials) on every poll.
 *
 * 200 = ok or degraded (details in the body), 503 = down (database unreachable).
 */
class HealthController extends AbstractController
{
    /** Async messages waiting longer than this (seconds) flip the queue to "degraded". */
    private const QUEUE_LAG_WARN_SECONDS = 300;

    /** A client with automatic crediting on should never have a "todo" payment older than this. */
    private const STUCK_PAYMENT_AGE = '-15 minutes';

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentRepository $paymentRepository,
        private readonly QueueStatus $queueStatus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/health', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        $checks = [];

        $checks['database'] = $this->checkDatabase();
        if ($checks['database']['status'] === 'down') {
            return $this->respond('down', $checks, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $checks['queue'] = $this->checkQueue();
        $checks['stuckPayments'] = $this->checkStuckPayments();

        $overall = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'degraded') {
                $overall = 'degraded';
            }
        }

        return $this->respond($overall, $checks, Response::HTTP_OK);
    }

    /**
     * @param array<string, array<string, mixed>> $checks
     */
    private function respond(string $status, array $checks, int $httpStatus): JsonResponse
    {
        return new JsonResponse([
            'status' => $status,
            'time' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => $checks,
        ], $httpStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->logger->error('Health check: database unreachable: {message}', ['message' => $e->getMessage()]);

            return ['status' => 'down'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQueue(): array
    {
        try {
            $failed = $this->queueStatus->failed();
            $lagSeconds = $this->queueStatus->oldestPendingSeconds();

            $status = ($failed > 0 || ($lagSeconds !== null && $lagSeconds > self::QUEUE_LAG_WARN_SECONDS)) ? 'degraded' : 'ok';

            return [
                'status' => $status,
                'pending' => $this->queueStatus->pending(),
                'failed' => $failed,
                'oldestPendingSeconds' => $lagSeconds,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Health check: queue probe failed: {message}', ['message' => $e->getMessage()]);

            return ['status' => 'degraded', 'error' => 'probe failed'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStuckPayments(): array
    {
        try {
            $count = $this->paymentRepository->countStuckAutomaticTodoPayments(new \DateTimeImmutable(self::STUCK_PAYMENT_AGE));

            return [
                'status' => $count > 0 ? 'degraded' : 'ok',
                'automaticTodoOlderThanThreshold' => $count,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Health check: stuck-payment probe failed: {message}', ['message' => $e->getMessage()]);

            return ['status' => 'degraded', 'error' => 'probe failed'];
        }
    }
}
