<?php

namespace App\Controller;

use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    /** A legacy-URL hit is recorded at most once per client per this interval. */
    private const LEGACY_SEEN_THROTTLE = '-1 hour';

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $webhookLimiter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/webhook/helloasso/{clientSlug}/{token}', name: 'webhook_helloasso', methods: ['POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function receive(string $clientSlug, string $token, Request $request): Response
    {
        if (!$this->webhookLimiter->create($clientSlug)->consume()->isAccepted()) {
            $this->logger->warning('HelloAsso webhook rate limit hit for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_TOO_MANY_REQUESTS);
        }

        $client = $this->clientRepository->findOneBySlug($clientSlug);

        if ($client === null || !$client->isActive()) {
            $this->logger->warning('HelloAsso webhook received for unknown or inactive client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        if (!hash_equals($client->getWebhookToken(), $token)) {
            // Same 404 as an unknown client: a wrong token must not confirm that
            // the slug exists.
            $this->logger->warning('HelloAsso webhook received with an invalid token for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            $this->logger->warning('HelloAsso webhook received malformed JSON for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('HelloAsso webhook received for client "{slug}"', ['slug' => $clientSlug]);

        $result = $this->paymentProcessor->handleWebhookNotification($client, $payload);

        return new JsonResponse([
            'status' => $result?->status->value ?? 'ignored',
        ]);
    }

    /**
     * Legacy token-less URL. Kept only so a still-unmigrated HelloAsso
     * configuration produces a loud, identifiable log line (and a 404 that
     * triggers HelloAsso's own retries) instead of silently hitting a missing
     * route — the payments themselves are still picked up by the periodic
     * `app:helloasso:fetch` safety net until the URL is updated.
     */
    #[Route(path: '/webhook/helloasso/{clientSlug}', name: 'webhook_helloasso_legacy', methods: ['POST'])]
    public function legacy(string $clientSlug): Response
    {
        $this->logger->warning('HelloAsso webhook called without a token for client "{slug}" — update the notification URL in HelloAsso to /webhook/helloasso/{slug}/<token> (see the admin client page)', ['slug' => $clientSlug]);

        // Leave a trace the admin dashboard can surface, but only for a real
        // active client and at most hourly — this endpoint is unauthenticated.
        $client = $this->clientRepository->findOneBySlug($clientSlug);
        if ($client !== null && $client->isActive()) {
            $lastSeen = $client->getLegacyWebhookLastSeenAt();
            if ($lastSeen === null || $lastSeen < new \DateTimeImmutable(self::LEGACY_SEEN_THROTTLE)) {
                $client->setLegacyWebhookLastSeenAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        }

        return new Response(status: Response::HTTP_NOT_FOUND);
    }
}
