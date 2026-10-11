<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Http;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form    when not empty, the request is a POST with application/x-www-form-urlencoded
     * @throws HttpException when no response was obtained (cannot connect, timeout and so on)
     */
    public function request(string $url, array $headers = [], array $form = []): HttpResponse;
}
