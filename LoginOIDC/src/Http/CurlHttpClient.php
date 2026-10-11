<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Http;

/**
 * curl による実装。
 *
 * TLS の検証は常に行い、無効にする手段は用意しない。独自の CA を使う場合は
 * CA 証明書のファイルを渡す。リダイレクトは追わない（IdP のエンドポイントが
 * 別の場所へ飛ばすことは想定せず、飛ばされたら失敗として扱う）。
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly string $caCertFile = '',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function request(string $url, array $headers = [], array $form = []): HttpResponse
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new HttpException('cannot initialize curl');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => "{$name}: {$value}",
                array_keys($headers),
                array_values($headers),
            ),
        ];
        if ($this->caCertFile !== '') {
            if (!is_readable($this->caCertFile)) {
                throw new HttpException("cannot read CA certificate file: {$this->caCertFile}");
            }
            $options[CURLOPT_CAINFO] = $this->caCertFile;
        }
        if ($form !== []) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        }
        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        if (!is_string($body)) {
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            throw new HttpException("cannot connect to {$host}: " . curl_error($curl));
        }

        return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);
    }
}
