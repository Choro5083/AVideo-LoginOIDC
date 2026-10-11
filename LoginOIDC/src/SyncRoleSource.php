<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** Which kind of assignment the Keycloak sync derives permissions from. */
enum SyncRoleSource: string
{
    case ClientRoles = 'clientRoles';
    case RealmRoles = 'realmRoles';
    case Groups = 'groups';
}
