<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

// AVideo 本体の vendor/ とは別に、このプラグインのクラスだけを読み込む。
spl_autoload_register(static function (string $class): void {
    $prefix = 'LoginOIDC\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
