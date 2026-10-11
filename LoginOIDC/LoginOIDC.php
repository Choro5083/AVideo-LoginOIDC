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
 * OpenID Connect によるログインを AVideo に追加する。
 *
 * このクラスは AVideo との接点だけを持つ。処理の中身は src/ に置き、
 * AVideo に依存しない形でテストできるようにする。
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

    /** 本体の言語設定に合わせて文言を翻訳する。訳が無ければ、英語のまま返す。 */
    public static function t(string $text): string
    {
        // 第2引数を付けないと、本体が引用符などを文字参照に変える。出力するときに、こちらでエスケープする。
        return __($text, true);
    }

    /** 翻訳して、HTML に出せる形にする。 */
    private static function html(string $text, string|int ...$arguments): string
    {
        return htmlspecialchars(vsprintf(self::t($text), $arguments), ENT_QUOTES, 'UTF-8');
    }

    /** プラグインの一覧に、設定の検査を開くボタンを出す。 */
    public function getPluginMenu()
    {
        return '<button onclick="avideoModalIframe(webSiteRootURL+\'plugin/LoginOIDC/check.php\')"'
            . ' class="btn btn-primary btn-sm btn-xs btn-block"><i class="fas fa-stethoscope"></i> ' . self::html('Check settings') . '</button>';
    }

    /**
     * 設定に問題があれば、プラグインの一覧の説明欄に出す。
     *
     * 本体には、設定を保存するときにプラグインが割り込む仕組みが無い。保存すると一覧が
     * 読み直されるので、ここに出しておけば、誤った設定を保存した直後に気づける。
     */
    private static function settingsStatus(): string
    {
        if (empty(AVideoPlugin::isEnabledByName('LoginOIDC'))) {
            return '';
        }
        try {
            $findings = SettingsReport::quick(self::settings(...));
        } catch (\Throwable $e) {
            // 説明欄を作れないだけで、プラグインの一覧まで落とさない。
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

        // --- 接続 ---
        $obj->issuer = '';
        $obj->clientId = '';
        // 本体の「encrypted」型。管理画面から保存すると、DB には暗号化して入る。
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

        // --- ログイン画面 ---
        $obj->buttonLabel = 'Login with SSO';
        $obj->autoRedirect = false;

        // --- ユーザ ---
        $obj->createUsers = true;
        $obj->usernameClaim = 'preferred_username';
        $obj->nameClaim = 'name';
        $obj->emailClaim = 'email';
        $obj->updateName = true;
        $obj->updateEmail = true;
        $obj->updateEmailVerified = true;

        // --- 権限と user group ---
        $obj->rolesClaimPath = '';
        $obj->managedFlags = '';
        $obj->flagMapping = self::textarea('{}');
        $obj->requiredClaimPath = '';
        $obj->requiredClaimValues = '';
        $obj->groupsClaimPath = '';
        $obj->managedGroups = '';
        $obj->groupMapping = self::textarea('{}');

        // --- ログアウト ---
        $obj->rpInitiatedLogout = true;
        $obj->backchannelLogout = true;

        // --- ネイティブログイン ---
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

        // --- Keycloak の同期 ---
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

        // --- その他 ---
        $obj->debugLog = false;

        foreach (self::helpTexts() as $property => [$name, $description]) {
            $this->addDataObjectHelper($property, self::t($name), $description === '' ? '' : self::t($description));
        }

        return $obj;
    }

    /**
     * 設定を、型の決まった値として読む。
     *
     * @throws InvalidSettingException 設定値が解釈できないとき
     */
    public static function settings(): Settings
    {
        // 本体が持つ設定のオブジェクトを書き換えないよう、写しに対して復号する
        // （書き換えると、他の処理が平文のまま保存し直すおそれがある）。
        $data = unserialize(serialize(AVideoPlugin::getDataObject('LoginOIDC')));

        return Settings::fromDataObject(self::decryptSecrets($data));
    }

    /**
     * 暗号化して保存された項目を元に戻す。
     *
     * 本体の Plugin::decryptIfNeed() は使わない。type が配列の項目（選択肢）があると、
     * 型の確認をせずに strtolower() に渡して落ちる。
     */
    private static function decryptSecrets(stdClass $data): stdClass
    {
        foreach (get_object_vars($data) as $value) {
            if (
                is_object($value)
                && is_string($value->type ?? null) && strtolower($value->type) === 'encrypted'
                && is_string($value->value ?? null) && $value->value !== ''
            ) {
                // 暗号化されていない値（管理画面を通さずに入れたもの）には false が返る。そのまま使う。
                $plain = Plugin::isEncrypted($value->value);
                if (is_string($plain)) {
                    $value->value = $plain;
                }
            }
        }

        return $data;
    }

    /**
     * リクエストの開始時。ログアウトの連動と、無効化されたセッションの確認を行う。
     *
     * このフックは、サイトのすべてのリクエストで呼ばれる。ここで例外を外に出すと、サイト全体が
     * 落ちる。想定していない失敗は記録するだけにして、本体の処理を続けさせる。
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
     * ログインの成立時。IdP を経由しないログインを、設定に従って取り消す。
     *
     * 判定の途中で想定していない失敗が起きたら、ログインを取り消す。通してしまうと、
     * 制御をすり抜けられるため。
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

    /** ログイン画面に出すボタン。設定が済んでいなければ出さない。 */
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
     * 設定画面に出す項目名と説明。
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
            'encoderAddresses' => ['Encoder addresses', 'One per line, an IP address or a CIDR range. Sign-ins from these addresses are not controlled.'],
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
