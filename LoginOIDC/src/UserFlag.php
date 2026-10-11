<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** The AVideo permission flags this plugin can manage. The values are column names of the users table. */
enum UserFlag: string
{
    case IsAdmin = 'isAdmin';
    case CanUpload = 'canUpload';
    case CanStream = 'canStream';
    case CanViewChart = 'canViewChart';
    case CanCreateMeet = 'canCreateMeet';
}
