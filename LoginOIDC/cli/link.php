<?php
// SPDX-License-Identifier: MIT

// Links an existing AVideo user to a user at the provider, or removes the link.
//
// The plugin never links users by itself, not even when the username or the e-mail
// address is the same (docs/spec.md). An admin does it here, knowing the sub.
//
//   php plugin/LoginOIDC/cli/link.php <username> <sub>     link
//   php plugin/LoginOIDC/cli/link.php --unlink <username>  remove the link and end the user's sessions
//   php plugin/LoginOIDC/cli/link.php --list               show the links
//
// Exit codes: 0 = done, 1 = failed, 2 = usage error

declare(strict_types=1);

use LoginOIDC\AVideo\AVideoIdentityStore;
use LoginOIDC\AVideo\Db;
use LoginOIDC\AVideo\DbException;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\User\DuplicateIdentityException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
$usage = static function (): never {
    fwrite(STDERR, "usage: php link.php <username> <sub>\n       php link.php --unlink <username>\n       php link.php --list\n");
    exit(2);
};
$mode = match (true) {
    $arguments === ['--list'] => 'list',
    count($arguments) === 2 && $arguments[0] === '--unlink' && $arguments[1] !== '' => 'unlink',
    count($arguments) === 2 && !str_starts_with($arguments[0], '--') && $arguments[1] !== '' => 'link',
    default => $usage(),
};

require_once __DIR__ . '/../../../videos/configuration.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "LoginOIDC link: {$message}\n");
    exit(1);
};
if (empty(AVideoPlugin::loadPluginIfEnabled('LoginOIDC'))) {
    $fail('the plugin is disabled');
}

try {
    $issuer = LoginOIDC::settings()->issuer;
    if ($issuer === '') {
        $fail('issuer is not configured');
    }
    $identities = new AVideoIdentityStore();

    if ($mode === 'list') {
        foreach ($identities->allLinked($issuer) as $identity) {
            fwrite(STDOUT, sprintf("%s\t%s\t%s\n", $identity->username, $identity->subject, $identity->active ? 'active' : 'disabled'));
        }
        exit(0);
    }

    $username = $mode === 'unlink' ? $arguments[1] : $arguments[0];
    $rows = Db::select('SELECT id FROM users WHERE user = ?', 's', [$username]);
    if ($rows === []) {
        $fail("user \"{$username}\" does not exist");
    }
    $userId = (int) $rows[0]['id'];

    if ($mode === 'unlink') {
        if (!$identities->unlink($userId)) {
            $fail("user \"{$username}\" is not linked");
        }
        // Sessions that came through the provider end at their next request, because the link is gone.
        _error_log("LoginOIDC link: unlinked user {$username} (id {$userId})", AVideoLog::$SECURITY);
        fwrite(STDOUT, "unlinked {$username}\n");
        exit(0);
    }

    $subject = $arguments[1];
    if (strlen($subject) > 255 || preg_match('/^[\x21-\x7e]+$/', $subject) !== 1) {
        $fail('the sub must be printable ASCII without spaces, at most 255 characters');
    }
    try {
        $identities->link($userId, $issuer, $subject);
    } catch (DuplicateIdentityException) {
        $fail("user \"{$username}\" or this sub is already linked. Use --list to see the links");
    }
    _error_log("LoginOIDC link: linked user {$username} (id {$userId}) to sub {$subject}", AVideoLog::$SECURITY);
    fwrite(STDOUT, "linked {$username} to {$subject} at {$issuer}\n");
} catch (InvalidSettingException | DbException $e) {
    $fail($e->getMessage());
}
