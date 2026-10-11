<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC\Http;

/** 通信そのものが失敗した、または応答を読めなかった。 */
final class HttpException extends \RuntimeException
{
}
