<?php

namespace App\Http;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Retry strategy for the Cyclos client: same status-code rules as the default one,
 * but a non-idempotent request (POST — i.e. `system/payments`) is never retried.
 * A retried payment POST could credit an account twice if the first attempt
 * actually went through and only the response was lost; the read calls
 * (user lookup, duplicate check) are safe to retry.
 */
final class NonMutatingRetryStrategy extends GenericRetryStrategy
{
    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $method = strtoupper((string) $context->getInfo('http_method'));

        if (!\in_array($method, self::IDEMPOTENT_METHODS, true)) {
            return false;
        }

        return parent::shouldRetry($context, $responseContent, $exception);
    }
}
