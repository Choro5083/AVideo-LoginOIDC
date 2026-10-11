<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * ファイルによるキャッシュ。
 *
 * 入れるのは公開情報（Discovery の結果と公開鍵）だけ。書けなくても動作は
 * 続けられるので、書き込みの失敗は例外にせず、次の読み出しで取り直させる。
 */
final class FileCache implements Cache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);
        $entry = $content === false ? null : json_decode($content, true);
        if (!is_array($entry) || !is_int($entry['expires'] ?? null) || !is_array($entry['value'] ?? null)) {
            return null;
        }

        return $entry['expires'] > time() ? $entry['value'] : null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return;
        }
        $file = $this->path($key);
        $temp = $file . '.' . bin2hex(random_bytes(4));
        $json = json_encode(['expires' => time() + $ttlSeconds, 'value' => $value]);
        if ($json === false || @file_put_contents($temp, $json, LOCK_EX) === false) {
            return;
        }
        // 途中まで書いたファイルを読ませないため、書き終えてから置き換える。
        @rename($temp, $file);
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }
}
