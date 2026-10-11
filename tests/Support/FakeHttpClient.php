<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Tests\Support;

use LoginOIDC\Http\HttpClient;
use LoginOIDC\Http\HttpException;
use LoginOIDC\Http\HttpResponse;

/** Returns a prepared response per URL and records the requests it received. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, form: array<string, string>}> */
    public array $requests = [];

    /** @param array<string, HttpResponse|list<HttpResponse>> $responses a list returns its next item on each call */
    public function __construct(private array $responses = [])
    {
    }

    public function request(string $url, array $headers = [], array $form = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'form' => $form];
        $response = $this->responses[$url] ?? throw new HttpException("unexpected URL: {$url}");
        if (is_array($response)) {
            $next = array_shift($this->responses[$url]) ?? throw new HttpException("no response left: {$url}");

            return $next;
        }

        return $response;
    }

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function countRequestsTo(string $url): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => $r['url'] === $url));
    }
}
