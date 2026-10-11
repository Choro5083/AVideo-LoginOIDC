<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Oidc;

/**
 * A cache in files.
 *
 * It only ever holds public information (the discovery result and public keys). The plugin
 * works without it, so a failed write is not an error: the next read just fetches again.
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
        // Replace the file only once it is fully written, so a half-written one is never read.
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
