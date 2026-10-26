<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use LoginOIDC\Admin\Finding;
use LoginOIDC\Admin\SettingsReport;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\AVideo\Translations;
use LoginOIDC\ClientAuthMethod;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;
use LoginOIDC\SyncRoleSource;

require_once __DIR__ . '/../Plugin.abstract.php';
require_once __DIR__ . '/src/autoload.php';

Translations::register(__DIR__ . '/locale');

/**
 * Adds sign-in with OpenID Connect to AVideo.
 *
 * This class is only the contact surface with AVideo. The logic lives in src/,
 * where it can be tested without AVideo.
 */
class LoginOIDC extends PluginAbstract
{
    public function getTags()
    {
        return [
            PluginTags::$FREE,
            PluginTags::$LOGIN,
            PluginTags::$SECURITY,
        ];
    }

    public function getName()
    {
        return 'LoginOIDC';
    }

    public function getUUID()
    {
        return '294f41b5-c2ac-43c8-be14-1269790d4975';
    }

    public function getPluginVersion()
    {
        return '1.0';
    }

    public function getDescription()
    {
        return self::html('Sign in with OpenID Connect (Authorization Code Flow + PKCE).')
            . '<br>' . self::html('Redirect URI to register at the provider') . ': <strong>' . self::callbackUrl() . '</strong>'
            . '<br>' . self::html('Back-channel logout URL') . ': <strong>' . self::backchannelLogoutUrl() . '</strong>'
            . self::settingsStatus();
    }

    /** Translates a text for AVideo's current language. Returns the English text when there is no translation. */
    public static function t(string $text): string
    {
        // Without the second argument AVideo turns quotes and the like into entities. We escape on output instead.
        return __($text, true);
    }

    /** Translates and escapes for HTML. */
    private static function html(string $text, string|int ...$arguments): string
    {
        return htmlspecialchars(vsprintf(self::t($text), $arguments), ENT_QUOTES, 'UTF-8');
    }

    /** Adds the button that opens the settings check to the plugin list. */
    public function getPluginMenu()
    {
        return '<button onclick="avideoModalIframe(webSiteRootURL+\'plugin/LoginOIDC/check.php\')"'
            . ' class="btn btn-primary btn-sm btn-xs btn-block"><i class="fas fa-stethoscope"></i> ' . self::html('Check settings') . '</button>';
    }

    /**
     * Shows problems in the settings in the description column of the plugin list.
     *
     * AVideo has no hook for a plugin to validate its settings when they are saved. The list is
     * reloaded after saving, so showing the problems here makes a bad save visible right away.
     */
    private static function settingsStatus(): string
    {
        if (empty(AVideoPlugin::isEnabledByName('LoginOIDC'))) {
            return '';
        }
        try {
            $findings = SettingsReport::quick(self::settings(...));
        } catch (\Throwable $e) {
            // Failing to build the description must not take the plugin list down.
            return '<br><span class="label label-danger">' . self::html('The settings could not be checked') . '</span> '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $errors = array_values(array_filter($findings, static fn (Finding $f): bool => $f->level === Finding::ERROR));
        $warnings = count($findings) - count($errors);
        if ($findings === []) {
            return '';
        }

        $html = '<br>';
        if ($errors !== []) {
            $first = htmlspecialchars("{$errors[0]->setting}: " . $errors[0]->message(self::t(...)), ENT_QUOTES, 'UTF-8');
            $html .= '<span class="label label-danger">' . self::html('%d error(s) in the settings', count($errors)) . '</span> ' . $first;
        }
        if ($warnings > 0) {
            $html .= ' <span class="label label-warning">' . self::html('%d warning(s)', $warnings) . '</span>';
        }

        return $html . ' ' . self::html('Open "Check settings" for the details.');
    }

    public static function callbackUrl(): string
    {
        global $global;

        return $global['webSiteRootURL'] . 'plugin/LoginOIDC/callback.php';
    }

    public static function backchannelLogoutUrl(): string
    {
        global $global;

        return $global['webSiteRootURL'] . 'plugin/LoginOIDC/backchannelLogout.php';
    }

    public function getEmptyDataObject()
    {
        $obj = new stdClass();

        // --- Connection ---
        $obj->issuer = '';
        $obj->clientId = '';
        // AVideo's "encrypted" type: saved from the admin page, it is stored encrypted in the database.
        $obj->clientSecret = self::encrypted();
        $obj->clientSecretFile = '';
        $obj->clientAuthMethod = self::select(
            [
                ClientAuthMethod::SecretBasic->value => 'client_secret_basic',
                ClientAuthMethod::SecretPost->value => 'client_secret_post',
            ],
            ClientAuthMethod::SecretBasic->value,
        );
        $obj->scopes = 'openid profile email';
        $obj->useUserInfo = false;
        $obj->caCertFile = '';
        $obj->clockSkewSeconds = 60;
        $obj->allowInsecureHttp = false;
        $obj->loginRateLimit = 60;

        // --- Sign-in page ---
        $obj->buttonLabel = 'Login with SSO';
        $obj->autoRedirect = false;

        // --- Users ---
        $obj->createUsers = true;
        $obj->usernameClaim = 'preferred_username';
        $obj->nameClaim = 'name';
        $obj->emailClaim = 'email';
        $obj->updateName = true;
        $obj->updateEmail = true;
        $obj->updateEmailVerified = true;

        // --- Permissions and user groups ---
        $obj->rolesClaimPath = '';
        $obj->managedFlags = '';
        $obj->flagMapping = self::textarea('{}');
        $obj->requiredClaimPath = '';
        $obj->requiredClaimValues = '';
        $obj->groupsClaimPath = '';
        $obj->managedGroups = '';
        $obj->groupMapping = self::textarea('{}');

        // --- Logout ---
        $obj->rpInitiatedLogout = true;
        $obj->backchannelLogout = true;

        // --- Native sign-in ---
        $obj->nativeLoginMode = self::select(
            [
                NativeLoginMode::Allow->value => self::t('No restriction'),
                NativeLoginMode::DenyLinked->value => self::t('Deny users linked to the provider'),
                NativeLoginMode::Allowlist->value => self::t('Allow only the users in the allowlist'),
            ],
            NativeLoginMode::Allow->value,
        );
        $obj->nativeLoginAllowlist = self::textarea('');
        $obj->encoderAddresses = self::textarea('');

        // --- Keycloak sync ---
        $obj->syncEnabled = false;
        $obj->syncAdminUrl = '';
        $obj->syncClientId = '';
        $obj->syncClientSecretFile = '';
        $obj->syncRoleSource = self::select(
            [
                SyncRoleSource::Groups->value => self::t('Groups (readable with view-users)'),
                SyncRoleSource::RealmRoles->value => self::t('Realm roles (needs view-realm)'),
                SyncRoleSource::ClientRoles->value => self::t('Client roles (needs view-clients, which can read every client secret)'),
            ],
            SyncRoleSource::Groups->value,
        );
        $obj->syncRoleClientId = '';
        $obj->syncMaxDisableRatio = 20;

        // --- Other ---
        $obj->debugLog = false;

        foreach (self::helpTexts() as $property => [$name, $description]) {
            $this->addDataObjectHelper($property, self::t($name), $description === '' ? '' : self::t($description));
        }

        return $obj;
    }

    /**
     * Reads the settings as typed values.
     *
     * @throws InvalidSettingException when a setting cannot be interpreted
     */
    public static function settings(): Settings
    {
        // Decrypt on a copy so the settings object AVideo holds is not modified
        // (otherwise other code could save it back with the secret in clear text).
        $data = unserialize(serialize(AVideoPlugin::getDataObject('LoginOIDC')));

        return Settings::fromDataObject(self::decryptSecrets($data));
    }

    /**
     * Decrypts the settings that are stored encrypted.
     *
     * AVideo's Plugin::decryptIfNeed() is not used. For a setting whose type is an array
     * (a select), it passes the array to strtolower() without checking the type and fails.
     */
    private static function decryptSecrets(stdClass $data): stdClass
    {
        foreach (get_object_vars($data) as $value) {
            if (
                is_object($value)
                && is_string($value->type ?? null) && strtolower($value->type) === 'encrypted'
                && is_string($value->value ?? null) && $value->value !== ''
            ) {
                // For a value that is not encrypted (entered without the admin page) this returns false. Use it as is.
                $plain = Plugin::isEncrypted($value->value);
                if (is_string($plain)) {
                    $value->value = $plain;
                }
            }
        }

        return $data;
    }

    /**
     * Start of a request. Links logout with the provider and ends revoked sessions.
     *
     * This hook runs for every request to the site. An exception escaping from here takes the
     * whole site down, so unexpected failures are logged and AVideo carries on.
     */
    public function getStart()
    {
        try {
            RequestGuard::onRequestStart();
        } catch (\Throwable $e) {
            _error_log('LoginOIDC: unexpected failure at request start: ' . $e->getMessage(), AVideoLog::$ERROR);
        }
    }

    /**
     * A sign-in just succeeded. Cancels sign-ins that bypass the provider, as configured.
     *
     * If the decision itself fails unexpectedly, the sign-in is cancelled. Letting it
     * through would be a way around the control.
     */
    public function onUserSignIn($users_id)
    {
        try {
            RequestGuard::onUserSignIn((int) $users_id);
        } catch (\Throwable $e) {
            _error_log('LoginOIDC: unexpected failure at sign-in, denying it: ' . $e->getMessage(), AVideoLog::$ERROR);
            User::logoff();
        }
    }

    /** The button for the sign-in page. Not shown until the plugin is configured. */
    public function getLogin()
    {
        try {
            $configured = self::settings()->isConfigured();
        } catch (InvalidSettingException $e) {
            _error_log('LoginOIDC: sign-in button hidden because the settings are invalid: ' . $e->getMessage(), AVideoLog::$ERROR);
            $configured = false;
        }

        return $configured ? __DIR__ . '/view/loginButton.php' : parent::getLogin();
    }

    /** @param array<string, string> $options */
    private static function select(array $options, string $default): stdClass
    {
        $o = new stdClass();
        $o->type = $options;
        $o->value = $default;

        return $o;
    }

    private static function encrypted(): stdClass
    {
        $o = new stdClass();
        $o->type = 'encrypted';
        $o->value = '';

        return $o;
    }

    private static function textarea(string $default): stdClass
    {
        $o = new stdClass();
        $o->type = 'textarea';
        $o->value = $default;

        return $o;
    }

    /**
     * Names and descriptions shown on the settings page.
     *
     * @return array<string, array{string, string}>
     */
    private static function helpTexts(): array
    {
        return [
            'issuer' => ['Issuer', 'URL of the provider\'s issuer. The plugin reads .well-known/openid-configuration from it.'],
            'clientId' => ['Client ID', ''],
            'clientSecret' => ['Client secret', 'Stored encrypted in the AVideo database. Leave it empty and use clientSecretFile to pass it in a file.'],
            'clientSecretFile' => ['Client secret file', 'Path of a file that contains the secret. When set, it takes precedence over clientSecret.'],
            'clientAuthMethod' => ['Client authentication method', 'How the plugin authenticates at the token endpoint.'],
            'scopes' => ['Scopes', 'Separated by spaces. openid is required.'],
            'useUserInfo' => ['Also read UserInfo', 'For providers that do not put the needed claims in the ID Token.'],
            'caCertFile' => ['CA certificate file', 'Path of the CA certificate when the provider uses a private CA. Empty uses the system default.'],
            'clockSkewSeconds' => ['Allowed clock skew (seconds)', 'Tolerance when checking exp and iat of the ID Token.'],
            'allowInsecureHttp' => ['Allow an http provider (development only)', 'Accepts http for the issuer and its endpoints. The traffic can be read on the network, so do not use it in production.'],
            'loginRateLimit' => ['Sign-in rate limit', 'How many sign-in starts and callbacks are accepted from one address in 5 minutes. 0 disables the limit. Behind a reverse proxy, everyone is counted as one address unless AVideo is configured to trust the proxy.'],
            'buttonLabel' => ['Sign-in button label', ''],
            'autoRedirect' => ['Send anonymous visitors to the provider', 'Applies to page views only. APIs and video files are not affected.'],
            'createUsers' => ['Create users on first sign-in', 'When disabled, only users linked in advance can sign in.'],
            'usernameClaim' => ['Claim for the username', 'Used only when the user is created. The username is never changed afterwards.'],
            'nameClaim' => ['Claim for the name', ''],
            'emailClaim' => ['Claim for the e-mail address', ''],
            'updateName' => ['Update the name on every sign-in', ''],
            'updateEmail' => ['Update the e-mail address on every sign-in', ''],
            'updateEmailVerified' => ['Update the e-mail verification state on every sign-in', 'From the email_verified claim.'],
            'rolesClaimPath' => ['Claim path for permissions', 'Dot-separated, for example resource_access.avideo.roles. Empty leaves the permissions untouched.'],
            'managedFlags' => ['Managed flags', 'Comma-separated: isAdmin, canUpload, canStream, canViewChart, canCreateMeet. Only the flags listed here are overwritten on every sign-in.'],
            'flagMapping' => ['Claim values to flags', 'JSON, for example {"admin": ["isAdmin"], "uploader": ["canUpload"]}'],
            'requiredClaimPath' => ['Claim path required to sign in', 'Empty applies no requirement.'],
            'requiredClaimValues' => ['Values required to sign in', 'Comma-separated. Having any one of them is enough.'],
            'groupsClaimPath' => ['Claim path for user groups', 'Empty leaves the user groups untouched.'],
            'managedGroups' => ['Managed user groups', 'Comma-separated user group IDs. Only the groups listed here are overwritten on every sign-in.'],
            'groupMapping' => ['Claim values to user groups', 'JSON, for example {"staff": [3]}'],
            'rpInitiatedLogout' => ['Sign out of the provider when signing out of AVideo', 'RP-Initiated Logout'],
            'backchannelLogout' => ['Accept logout notices from the provider', 'Back-Channel Logout'],
            'nativeLoginMode' => ['Native sign-in control', 'How far sign-in with a username and password is allowed.'],
            'nativeLoginAllowlist' => ['Usernames allowed to sign in natively', 'One per line. Used when the control is set to the allowlist.'],
            'encoderAddresses' => ['Encoder addresses', 'One per line, an IP address or a CIDR range. Sign-ins from these addresses are not controlled. Behind a reverse proxy, list the address of the Encoder itself and the proxy in the trustedProxies of AVideo, never the proxy here.'],
            'syncEnabled' => ['Use the Keycloak sync', 'Run the CLI from cron.'],
            'syncAdminUrl' => ['Admin API URL', 'Empty derives it from the issuer.'],
            'syncClientId' => ['Client ID for the sync', 'A client dedicated to the sync, with a service account.'],
            'syncClientSecretFile' => ['Client secret file for the sync', 'The secret is never stored in the database. It can also be passed in the environment variable LOGINOIDC_SYNC_CLIENT_SECRET.'],
            'syncRoleSource' => ['What the sync reads', 'Choose what gives the same values as the sign-in claim (rolesClaimPath). If they differ, sign-in and sync assign different permissions.'],
            'syncRoleClientId' => ['Client ID whose client roles the sync reads', 'Used when the sync reads client roles.'],
            'syncMaxDisableRatio' => ['Share of users one sync may disable (%)', 'Above it, the sync stops without changing anything.'],
            'debugLog' => ['Verbose log', 'Token contents are never logged.'],
        ];
    }
}
