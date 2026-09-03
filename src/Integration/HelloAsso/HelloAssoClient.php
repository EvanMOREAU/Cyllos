<?php

namespace App\Integration\HelloAsso;

use App\ActivityLog\ApiCallLogger;
use App\Entity\HelloAssoConfig;
use App\Security\SecretEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the HelloAsso API for a given client's configuration.
 *
 * Ported from HelloAssoService.java, parameterized by HelloAssoConfig instead of
 * a single global .env configuration.
 */
class HelloAssoClient
{
    private const REQUEST_TIMEOUT = 60.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly LoggerInterface $logger,
        private readonly ApiCallLogger $apiCallLogger,
    ) {
    }

    /**
     * Structurally parses a "Payment" webhook body. Returns null for anything that
     * isn't a well-formed payment notification (scam/empty body, "Order" events
     * which are sent alongside "Payment" events and must be ignored to avoid double
     * crediting, or malformed data).
     *
     * `data.amount` is accepted both as a flat integer of cents (the shape
     * HelloAsso sends in a "Payment" webhook, and what the v5 payments API
     * returns) and as a `{total, vat, discount}` object (the shape carried on the
     * "Order" event and older payloads).
     */
    public function parseNotification(array $rawPayload): ?HelloAssoNotificationPayload
    {
        $eventType = $rawPayload['eventType'] ?? null;
        $data = $rawPayload['data'] ?? null;

        if ($eventType === null || $data === null || !\is_array($data)) {
            $this->logger->debug('HelloAsso notification: empty or malformed input (must be scam)');

            return null;
        }

        if ($eventType === 'Order') {
            // Both a payment and an order notification are sent by HelloAsso for the
            // same event; only the "Payment" one is processed, to avoid double credit.
            $this->logger->debug('HelloAsso notification: ignoring Order event type');

            return null;
        }

        if ($eventType !== 'Payment') {
            $this->logger->error('HelloAsso notification: unexpected event type {type}', ['type' => $eventType]);

            return null;
        }

        $id = $data['id'] ?? null;
        $rawAmount = $data['amount'] ?? null;
        $amount = \is_array($rawAmount) ? ($rawAmount['total'] ?? null) : $rawAmount;
        $date = $data['date'] ?? null;
        $state = $data['state'] ?? null;
        $payer = $data['payer'] ?? null;
        $order = $data['order'] ?? null;

        if ($id === null || !is_numeric($amount) || !\is_array($payer) || !\is_array($order)) {
            $this->logger->error('HelloAsso notification: missing or malformed required fields', [
                'hasId' => $id !== null,
                'amountType' => get_debug_type($rawAmount),
                'hasPayer' => \is_array($payer),
                'hasOrder' => \is_array($order),
            ]);

            return null;
        }

        return new HelloAssoNotificationPayload(
            helloAssoPaymentId: (int) $id,
            amountCents: (int) $amount,
            rawDate: (string) ($date ?? ''),
            state: (string) ($state ?? ''),
            payerFirstName: (string) ($payer['firstName'] ?? ''),
            payerLastName: (string) ($payer['lastName'] ?? ''),
            payerEmail: (string) ($payer['email'] ?? ''),
            formSlug: (string) ($order['formSlug'] ?? ''),
        );
    }

    /**
     * @return HelloAssoFetchedPayment[]
     */
    public function fetchPaymentsHistory(HelloAssoConfig $config, int $nbDays): array
    {
        $token = $this->getAccessToken($config);

        try {
            $now = new \DateTimeImmutable();
            $beginDate = $now->modify(\sprintf('-%d days', $nbDays));
            $query = [
                'from' => $beginDate->format(DATE_ATOM),
                'to' => $now->format(DATE_ATOM),
                'states' => 'Authorized',
            ];
            $url = $this->buildUrl($config, \sprintf(
                'v5/organizations/%s/forms/%s/%s/payments',
                rawurlencode($config->getOrganizationSlug()),
                rawurlencode($config->getFormType()),
                rawurlencode($config->getFormSlug()),
            ));

            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $this->apiCallLogger->record('helloasso', 'GET', $url, json_encode($query, JSON_PRETTY_PRINT), $response->getStatusCode(), $response->getContent(false), 'Récupération des paiements HelloAsso');

            if ($response->getStatusCode() >= 300) {
                $this->logger->error('HelloAsso payment history fetch failed with status {status} (organization={org}, formType={type}, formSlug={form}): {body}', [
                    'status' => $response->getStatusCode(),
                    'org' => $config->getOrganizationSlug(),
                    'type' => $config->getFormType(),
                    'form' => $config->getFormSlug(),
                    'body' => $response->getContent(false),
                ]);

                return [];
            }

            $body = $response->toArray(false);
            $payments = [];
            foreach ($body['data'] ?? [] as $item) {
                if (!isset($item['id'], $item['amount'])) {
                    continue;
                }
                $payer = $item['payer'] ?? [];
                $payments[] = new HelloAssoFetchedPayment(
                    helloAssoPaymentId: (int) $item['id'],
                    amountCents: (int) $item['amount'],
                    rawDate: (string) ($item['date'] ?? ''),
                    payerFirstName: (string) ($payer['firstName'] ?? ''),
                    payerLastName: (string) ($payer['lastName'] ?? ''),
                    payerEmail: (string) ($payer['email'] ?? ''),
                );
            }

            return $payments;
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error fetching HelloAsso payment history: {message}', ['message' => $exception->getMessage()]);
            $this->apiCallLogger->record('helloasso', 'GET', $this->buildUrl($config, 'v5/organizations/.../payments'), null, 0, $exception->getMessage());

            return [];
        } finally {
            $this->disconnect($config, $token);
        }
    }

    /**
     * Looks up an alternative email for a payment, via a custom order item field
     * (used when the payer used a different email than their Cyclos account).
     */
    public function getAlternativeEmail(HelloAssoConfig $config, int $paymentId): ?string
    {
        try {
            $token = $this->getAccessToken($config);
        } catch (HelloAssoException $exception) {
            $this->logger->error('Error fetching HelloAsso token for alternative email lookup: {message}', ['message' => $exception->getMessage()]);

            return null;
        }

        try {
            $paymentUrl = $this->buildUrl($config, 'v5/payments/' . $paymentId);
            $paymentResponse = $this->httpClient->request('GET', $paymentUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $this->apiCallLogger->record('helloasso', 'GET', $paymentUrl, null, $paymentResponse->getStatusCode(), $paymentResponse->getContent(false), 'Recherche email alternatif — paiement');

            if ($paymentResponse->getStatusCode() >= 300) {
                $this->logger->error('Error fetching HelloAsso payment {id}', ['id' => $paymentId]);

                return null;
            }

            $orderId = $paymentResponse->toArray(false)['order']['id'] ?? null;
            if ($orderId === null) {
                return null;
            }

            $orderUrl = $this->buildUrl($config, 'v5/orders/' . $orderId);
            $orderResponse = $this->httpClient->request('GET', $orderUrl, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $this->apiCallLogger->record('helloasso', 'GET', $orderUrl, null, $orderResponse->getStatusCode(), $orderResponse->getContent(false), 'Recherche email alternatif — commande');

            if ($orderResponse->getStatusCode() >= 300) {
                $this->logger->error('Error fetching HelloAsso order {id}', ['id' => $orderId]);

                return null;
            }

            $items = $orderResponse->toArray(false)['items'] ?? [];
            $fieldName = $config->getExtraMailFieldName();

            foreach ($items as $item) {
                foreach ($item['customFields'] ?? [] as $field) {
                    $matchesFieldName = $fieldName !== null && ($field['name'] ?? null) === $fieldName;
                    $looksLikeEmail = isset($field['answer']) && str_contains((string) $field['answer'], '@');
                    if ($matchesFieldName || $looksLikeEmail) {
                        return (string) $field['answer'];
                    }
                }
            }

            return null;
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->error('Error during HelloAsso alternative email lookup: {message}', ['message' => $exception->getMessage()]);

            return null;
        } finally {
            $this->disconnect($config, $token);
        }
    }

    /**
     * The request contains client_id/client_secret and the response contains a
     * live bearer token — neither is safe to store in the audit trail, so this
     * call is logged with both bodies redacted, keeping only the method/URL/
     * status for traceability.
     */
    private function getAccessToken(HelloAssoConfig $config): string
    {
        $url = $this->buildUrl($config, 'oauth2/token');

        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => [
                    'client_id' => $config->getHelloAssoClientId(),
                    'client_secret' => $this->secretEncryptor->decrypt($config->getClientSecretEncrypted()),
                    'grant_type' => 'client_credentials',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            $data = $response->toArray(false);
            $accessToken = $data['access_token'] ?? null;

            if (!\is_string($accessToken) || $accessToken === '') {
                // Not a token response — most likely a standard OAuth2 error body
                // (e.g. {"error":"invalid_client","error_description":"..."}), which
                // never contains a secret, unlike a real token response. Safe to log
                // and surface as-is, so a misconfigured client_id/secret is
                // diagnosable from the journal/exception instead of a bare "no
                // access_token" message.
                $reason = $this->describeTokenError($data);
                $this->apiCallLogger->record('helloasso', 'POST', $url, '(identifiants OAuth2 — non journalisés)', $response->getStatusCode(), $reason, 'Authentification OAuth2 — échec');

                throw new HelloAssoException(\sprintf('HelloAsso token response did not contain an access_token (HTTP %d): %s', $response->getStatusCode(), $reason));
            }

            $this->apiCallLogger->record('helloasso', 'POST', $url, '(identifiants OAuth2 — non journalisés)', $response->getStatusCode(), '(jeton d\'accès — non journalisé)', 'Authentification OAuth2');

            return $accessToken;
        } catch (HttpClientExceptionInterface $exception) {
            $this->apiCallLogger->record('helloasso', 'POST', $url, '(identifiants OAuth2 — non journalisés)', 0, $exception->getMessage(), 'Authentification OAuth2');

            throw new HelloAssoException('Failed to fetch HelloAsso access token: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Extracts only the standard OAuth2 error fields ("error" / "error_description")
     * from a failed token response — never the raw body, in case HelloAsso ever
     * echoes back something unexpected. Falls back to a generic message if the
     * response doesn't even look like a recognizable OAuth2 error.
     */
    private function describeTokenError(array $data): string
    {
        $error = $data['error'] ?? null;
        $description = $data['error_description'] ?? null;

        if (\is_string($error) && $error !== '') {
            return \is_string($description) && $description !== ''
                ? \sprintf('%s (%s)', $error, $description)
                : $error;
        }

        return 'réponse inattendue de HelloAsso (ni access_token, ni erreur OAuth2 reconnaissable)';
    }

    private function disconnect(HelloAssoConfig $config, string $token): void
    {
        try {
            $this->httpClient->request('GET', $this->buildUrl($config, 'oauth2/disconnect'), [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->debug('Error disconnecting HelloAsso token: {message}', ['message' => $exception->getMessage()]);
        }
    }

    private function buildUrl(HelloAssoConfig $config, string $path): string
    {
        return $config->getApiUrl() . $path;
    }
}
