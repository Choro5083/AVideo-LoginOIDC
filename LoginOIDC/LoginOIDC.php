<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use LoginOIDC\Admin\Finding;
use LoginOIDC\Admin\SettingsReport;
use LoginOIDC\AVideo\RequestGuard;
use LoginOIDC\ClientAuthMethod;
use LoginOIDC\InvalidSettingException;
use LoginOIDC\NativeLoginMode;
use LoginOIDC\Settings;
use LoginOIDC\SyncRoleSource;

require_once __DIR__ . '/../Plugin.abstract.php';
require_once __DIR__ . '/src/autoload.php';

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
        return 'Sign in with OpenID Connect (Authorization Code Flow + PKCE).'
            . '<br>Redirect URI to register at the provider: <strong>' . self::callbackUrl() . '</strong>'
            . '<br>Back-channel logout URL: <strong>' . self::backchannelLogoutUrl() . '</strong>'
            . self::settingsStatus();
    }

    /** プラグインの一覧に、設定の検査を開くボタンを出す。 */
    public function getPluginMenu()
    {
        return '<button onclick="avideoModalIframe(webSiteRootURL+\'plugin/LoginOIDC/check.php\')"'
            . ' class="btn btn-primary btn-sm btn-xs btn-block"><i class="fas fa-stethoscope"></i> Check settings</button>';
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
            return '<br><span class="label label-danger">The settings could not be checked</span> '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $errors = array_values(array_filter($findings, static fn (Finding $f): bool => $f->level === Finding::ERROR));
        $warnings = count($findings) - count($errors);
        if ($findings === []) {
            return '';
        }

        $html = '<br>';
        if ($errors !== []) {
            $first = htmlspecialchars("{$errors[0]->setting}: {$errors[0]->message}", ENT_QUOTES, 'UTF-8');
            $html .= '<span class="label label-danger">' . count($errors) . ' error(s) in the settings</span> ' . $first;
        }
        if ($warnings > 0) {
            $html .= ' <span class="label label-warning">' . $warnings . ' warning(s)</span>';
        }

        return $html . ' &mdash; open "Check settings" for the details.';
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
                NativeLoginMode::Allow->value => '制限しない',
                NativeLoginMode::DenyLinked->value => 'IdP に紐付いたユーザだけ禁止する',
                NativeLoginMode::Allowlist->value => '許可リストのユーザだけ許可する',
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
                SyncRoleSource::Groups->value => 'group（view-users で読める）',
                SyncRoleSource::RealmRoles->value => 'realm role（view-realm が要る）',
                SyncRoleSource::ClientRoles->value => 'client role（view-clients が要る。全 client のシークレットを読める権限）',
            ],
            SyncRoleSource::Groups->value,
        );
        $obj->syncRoleClientId = '';
        $obj->syncMaxDisableRatio = 20;

        // --- その他 ---
        $obj->debugLog = false;

        foreach (self::helpTexts() as $property => [$name, $description]) {
            $this->addDataObjectHelper($property, $name, $description);
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
            'issuer' => ['Issuer', 'IdP の issuer の URL。ここから .well-known/openid-configuration を読む'],
            'clientId' => ['Client ID', ''],
            'clientSecret' => ['Client secret', 'AVideo の DB に暗号化して保存される。ファイルで渡すなら空にして clientSecretFile を使う'],
            'clientSecretFile' => ['Client secret のファイル', 'シークレットを書いたファイルのパス。指定すると clientSecret より優先する'],
            'clientAuthMethod' => ['クライアント認証の方式', 'トークンエンドポイントでの認証の方式'],
            'scopes' => ['Scope', '空白区切り。openid は必須'],
            'useUserInfo' => ['UserInfo も読む', 'ID Token に必要な claim が入らない IdP で使う'],
            'caCertFile' => ['CA 証明書のファイル', '独自の CA を使う場合のパス。空なら OS の既定を使う'],
            'clockSkewSeconds' => ['時刻のずれの許容（秒）', 'ID Token の exp / iat の検証で許すずれ'],
            'allowInsecureHttp' => ['http の IdP を許す（開発用）', 'issuer とエンドポイントに http を許す。通信を盗み見られるので、本番では使わない'],
            'loginRateLimit' => ['ログインの回数の上限', '同じ接続元から、5 分間に受け付けるログインの開始とコールバックの回数。0 で制限しない。リバースプロキシの配下では、AVideo 本体でプロキシを信頼する設定をしないと、全員が同じ接続元として数えられる'],
            'buttonLabel' => ['ログインボタンの表示', ''],
            'autoRedirect' => ['未ログインなら IdP へ自動でリダイレクトする', '画面の表示だけが対象。API や動画ファイルは対象外'],
            'createUsers' => ['初回ログイン時にユーザを作る', '無効にすると、事前に紐付けたユーザだけがログインできる'],
            'usernameClaim' => ['username に使う claim', '作成時にだけ使う。作成後は変えない'],
            'nameClaim' => ['名前に使う claim', ''],
            'emailClaim' => ['メールアドレスに使う claim', ''],
            'updateName' => ['ログインのたびに名前を更新する', ''],
            'updateEmail' => ['ログインのたびにメールアドレスを更新する', ''],
            'updateEmailVerified' => ['ログインのたびにメール確認の状態を更新する', 'email_verified claim から'],
            'rolesClaimPath' => ['権限に使う claim のパス', 'ドット区切り。例: resource_access.avideo.roles。空なら権限に触れない'],
            'managedFlags' => ['管理するフラグ', 'カンマ区切り。isAdmin, canUpload, canStream, canViewChart, canCreateMeet。ここに書いたフラグだけをログインのたびに上書きする'],
            'flagMapping' => ['claim の値からフラグへの対応', 'JSON。例: {"admin": ["isAdmin"], "uploader": ["canUpload"]}'],
            'requiredClaimPath' => ['ログインに必要な claim のパス', '空なら条件を付けない'],
            'requiredClaimValues' => ['ログインに必要な値', 'カンマ区切り。どれかを含めばログインを許す'],
            'groupsClaimPath' => ['user group に使う claim のパス', '空なら user group に触れない'],
            'managedGroups' => ['管理する user group', 'user group の ID をカンマ区切りで。ここに書いた group だけをログインのたびに上書きする'],
            'groupMapping' => ['claim の値から user group への対応', 'JSON。例: {"staff": [3]}'],
            'rpInitiatedLogout' => ['ログアウト時に IdP もログアウトする', 'RP-Initiated Logout'],
            'backchannelLogout' => ['IdP からのログアウト通知を受け付ける', 'Back-Channel Logout'],
            'nativeLoginMode' => ['ネイティブログインの制御', 'ユーザ名とパスワードでのログインをどこまで許すか'],
            'nativeLoginAllowlist' => ['ネイティブログインを許す username', '1行に1つ。制御が「許可リスト」のときに使う'],
            'encoderAddresses' => ['Encoder のアドレス', '1行に1つ。IP か CIDR。ここからのログインには制御を掛けない'],
            'syncEnabled' => ['Keycloak の同期を使う', 'cron から CLI を実行する'],
            'syncAdminUrl' => ['Admin API の URL', '空なら issuer から導出する'],
            'syncClientId' => ['同期用の Client ID', 'service account を持つ、同期専用の client'],
            'syncClientSecretFile' => ['同期用の Client secret のファイル', 'シークレットは DB に保存しない。環境変数 LOGINOIDC_SYNC_CLIENT_SECRET でも渡せる'],
            'syncRoleSource' => ['同期で読む割り当ての種類', 'ログイン時の claim（rolesClaimPath）と同じ内容になるように選ぶ。食い違うと、ログインと同期で権限が食い違う'],
            'syncRoleClientId' => ['同期で読む client role の Client ID', '種類が client role のときに使う'],
            'syncMaxDisableRatio' => ['1回の同期で無効化してよい割合（%）', '超えたら何も変更せずに止める'],
            'debugLog' => ['詳細なログを出す', 'トークンの中身は出さない'],
        ];
    }
}
