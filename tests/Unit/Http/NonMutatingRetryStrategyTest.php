<?php

namespace App\Tests\Unit\Http;

use App\Http\NonMutatingRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * The Cyclos client retries transient failures on reads but must never replay a
 * payment POST — a lost response on an already-applied payment would double-credit.
 */
class NonMutatingRetryStrategyTest extends TestCase
{
    public function testRetriesAFailedGet(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('{"ok":true}', ['http_code' => 200]),
        ]);
        $client = new RetryableHttpClient($mock, new NonMutatingRetryStrategy(delayMs: 0), 3);

        $response = $client->request('GET', 'https://cyclos.test/api/users');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $mock->getRequestsCount());
    }

    public function testDoesNotRetryAFailedPost(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('{"ok":true}', ['http_code' => 200]),
        ]);
        $client = new RetryableHttpClient($mock, new NonMutatingRetryStrategy(delayMs: 0), 3);

        $response = $client->request('POST', 'https://cyclos.test/api/system/payments', ['json' => ['amount' => '10']]);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(1, $mock->getRequestsCount());
    }
}
