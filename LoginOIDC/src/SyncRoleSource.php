<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/** Keycloak の同期で、権限の元にする割り当ての種類。 */
enum SyncRoleSource: string
{
    case ClientRoles = 'clientRoles';
    case RealmRoles = 'realmRoles';
    case Groups = 'groups';
}
