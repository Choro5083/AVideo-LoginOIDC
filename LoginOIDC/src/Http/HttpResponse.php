<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 本文を JSON オブジェクトとして読む。
     *
     * @return array<string, mixed>
     * @throws HttpException JSON オブジェクトでないとき
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpException("response is not JSON (HTTP {$this->status})", 0, $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new HttpException("response is not a JSON object (HTTP {$this->status})");
        }

        return $decoded;
    }
}
