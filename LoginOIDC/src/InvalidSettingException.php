<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** 設定値が解釈できないときに投げる。メッセージには項目名を含める。 */
final class InvalidSettingException extends \InvalidArgumentException
{
}
