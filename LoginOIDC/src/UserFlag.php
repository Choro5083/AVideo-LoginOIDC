<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** プラグインが管理できる AVideo の権限フラグ。値は users テーブルの列名。 */
enum UserFlag: string
{
    case IsAdmin = 'isAdmin';
    case CanUpload = 'canUpload';
    case CanStream = 'canStream';
    case CanViewChart = 'canViewChart';
    case CanCreateMeet = 'canCreateMeet';
}
