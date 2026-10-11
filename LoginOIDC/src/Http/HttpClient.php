<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Http;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form    空でなければ application/x-www-form-urlencoded の POST にする
     * @throws HttpException 接続できない、タイムアウトしたなど、応答を得られなかったとき
     */
    public function request(string $url, array $headers = [], array $form = []): HttpResponse;
}
