<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** Thrown when a setting cannot be interpreted. The message includes the setting name. */
final class InvalidSettingException extends \InvalidArgumentException
{
}
