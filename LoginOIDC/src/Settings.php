<?php
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace LoginOIDC;

/**
 * The plugin settings: AVideo's data object turned into typed values.
 *
 * AVideo's settings page only knows strings, booleans, selects and multi-line text,
 * so lists and mappings arrive as strings and are interpreted here. A value that cannot
 * be interpreted stops with InvalidSettingException. It never falls back silently to a default.
 */
final class Settings
{
    /**
     * @param list<string>                   $scopes
     * @param list<UserFlag>                 $managedFlags
     * @param array<string, list<UserFlag>>  $flagMapping          claim value → flags to grant
     * @param list<string>                   $requiredClaimValues  having any one of them allows the sign-in
     * @param list<int>                      $managedGroups        IDs of the managed user groups
     * @param array<string, list<int>>       $groupMapping         claim value → user group IDs
     * @param list<string>                   $nativeLoginAllowlist usernames allowed to sign in natively
     * @param list<string>                   $encoderAddresses     Encoder IPs / CIDR ranges exempt from the control
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $clientSecretFile,
        public readonly ClientAuthMethod $clientAuthMethod,
        public readonly array $scopes,
        public readonly bool $useUserInfo,
        public readonly string $caCertFile,
        public readonly int $clockSkewSeconds,
        public readonly bool $allowInsecureHttp,
        public readonly int $loginRateLimit,
        public readonly string $buttonLabel,
        public readonly bool $autoRedirect,
        public readonly bool $createUsers,
        public readonly string $usernameClaim,
        public readonly string $nameClaim,
        public readonly string $emailClaim,
        public readonly bool $updateName,
        public readonly bool $updateEmail,
        public readonly bool $updateEmailVerified,
        public readonly string $rolesClaimPath,
        public readonly array $managedFlags,
        public readonly array $flagMapping,
        public readonly string $requiredClaimPath,
        public readonly array $requiredClaimValues,
        public readonly string $groupsClaimPath,
        public readonly array $managedGroups,
        public readonly array $groupMapping,
        public readonly bool $rpInitiatedLogout,
        public readonly bool $backchannelLogout,
        public readonly NativeLoginMode $nativeLoginMode,
        public readonly array $nativeLoginAllowlist,
        public readonly array $encoderAddresses,
        public readonly bool $syncEnabled,
        public readonly string $syncAdminUrl,
        public readonly string $syncClientId,
        public readonly string $syncClientSecretFile,
        public readonly SyncRoleSource $syncRoleSource,
        public readonly string $syncRoleClientId,
        public readonly int $syncMaxDisableRatio,
        public readonly bool $debugLog,
    ) {
    }

    /**
     * Builds the settings from AVideo's data object.
     *
     * @throws InvalidSettingException when a value cannot be interpreted
     */
    public static function fromDataObject(object $data): self
    {
        $raw = self::unwrap($data);

        return new self(
            issuer: rtrim(self::string($raw, 'issuer'), '/'),
            clientId: self::string($raw, 'clientId'),
            clientSecret: self::string($raw, 'clientSecret'),
            clientSecretFile: self::string($raw, 'clientSecretFile'),
            clientAuthMethod: self::enum($raw, 'clientAuthMethod', ClientAuthMethod::class),
            scopes: self::parseList(self::string($raw, 'scopes'), ' '),
            useUserInfo: self::bool($raw, 'useUserInfo'),
            caCertFile: self::string($raw, 'caCertFile'),
            clockSkewSeconds: self::intInRange($raw, 'clockSkewSeconds', 0, 600),
            allowInsecureHttp: self::bool($raw, 'allowInsecureHttp'),
            loginRateLimit: self::intInRange($raw, 'loginRateLimit', 0, 100000),
            buttonLabel: self::string($raw, 'buttonLabel'),
            autoRedirect: self::bool($raw, 'autoRedirect'),
            createUsers: self::bool($raw, 'createUsers'),
            usernameClaim: self::string($raw, 'usernameClaim'),
            nameClaim: self::string($raw, 'nameClaim'),
            emailClaim: self::string($raw, 'emailClaim'),
            updateName: self::bool($raw, 'updateName'),
            updateEmail: self::bool($raw, 'updateEmail'),
            updateEmailVerified: self::bool($raw, 'updateEmailVerified'),
            rolesClaimPath: self::string($raw, 'rolesClaimPath'),
            managedFlags: self::parseFlags('managedFlags', self::parseList(self::string($raw, 'managedFlags'))),
            flagMapping: self::parseFlagMapping('flagMapping', self::string($raw, 'flagMapping')),
            requiredClaimPath: self::string($raw, 'requiredClaimPath'),
            requiredClaimValues: self::parseList(self::string($raw, 'requiredClaimValues')),
            groupsClaimPath: self::string($raw, 'groupsClaimPath'),
            managedGroups: self::parseIds('managedGroups', self::parseList(self::string($raw, 'managedGroups'))),
            groupMapping: self::parseGroupMapping('groupMapping', self::string($raw, 'groupMapping')),
            rpInitiatedLogout: self::bool($raw, 'rpInitiatedLogout'),
            backchannelLogout: self::bool($raw, 'backchannelLogout'),
            nativeLoginMode: self::enum($raw, 'nativeLoginMode', NativeLoginMode::class),
            nativeLoginAllowlist: self::parseList(self::string($raw, 'nativeLoginAllowlist')),
            encoderAddresses: self::parseList(self::string($raw, 'encoderAddresses')),
            syncEnabled: self::bool($raw, 'syncEnabled'),
            syncAdminUrl: rtrim(self::string($raw, 'syncAdminUrl'), '/'),
            syncClientId: self::string($raw, 'syncClientId'),
            syncClientSecretFile: self::string($raw, 'syncClientSecretFile'),
            syncRoleSource: self::enum($raw, 'syncRoleSource', SyncRoleSource::class),
            syncRoleClientId: self::string($raw, 'syncRoleClientId'),
            syncMaxDisableRatio: self::intInRange($raw, 'syncMaxDisableRatio', 0, 100),
            debugLog: self::bool($raw, 'debugLog'),
        );
    }

    /** Whether the minimum needed for a sign-in (issuer and clientId) is set. */
    public function isConfigured(): bool
    {
        return $this->issuer !== '' && $this->clientId !== '';
    }

    /**
     * The client secret. A configured file takes precedence.
     *
     * @throws InvalidSettingException when the file cannot be read or the secret is empty
     */
    public function resolveClientSecret(): string
    {
        return self::readSecret('clientSecretFile', $this->clientSecretFile, $this->clientSecret, 'clientSecret');
    }

    /**
     * The secret of the sync client. Never stored in the database: it comes from the environment or a file.
     *
     * @param  string $fromEnvironment value of the environment variable LOGINOIDC_SYNC_CLIENT_SECRET, or an empty string
     * @throws InvalidSettingException
     */
    public function resolveSyncClientSecret(string $fromEnvironment): string
    {
        return self::readSecret('syncClientSecretFile', $this->syncClientSecretFile, $fromEnvironment, 'syncClientSecretFile');
    }

    /** @throws InvalidSettingException */
    private static function readSecret(string $fileSetting, string $file, string $inline, string $inlineSetting): string
    {
        if ($file === '') {
            if ($inline === '') {
                throw new InvalidSettingException("{$inlineSetting}: no secret is configured");
            }

            return $inline;
        }
        $content = is_readable($file) ? file_get_contents($file) : false;
        if ($content === false) {
            throw new InvalidSettingException("{$fileSetting}: cannot read file: {$file}");
        }
        $secret = trim($content);
        if ($secret === '') {
            throw new InvalidSettingException("{$fileSetting}: file is empty: {$file}");
        }

        return $secret;
    }

    /**
     * Splits at the separator and at line breaks, trims, and drops empty items.
     *
     * @return list<string>
     */
    public static function parseList(string $text, string $separator = ','): array
    {
        $parts = preg_split('/[' . preg_quote($separator, '/') . '\r\n]+/', $text);
        if ($parts === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Interprets the JSON of "claim value → list of flags".
     *
     * @return array<string, list<UserFlag>>
     * @throws InvalidSettingException
     */
    public static function parseFlagMapping(string $name, string $json): array
    {
        $result = [];
        foreach (self::decodeMapping($name, $json) as $claimValue => $flags) {
            $result[$claimValue] = self::parseFlags("{$name}.{$claimValue}", $flags);
        }

        return $result;
    }

    /**
     * Interprets the JSON of "claim value → list of user group IDs".
     *
     * @return array<string, list<int>>
     * @throws InvalidSettingException
     */
    public static function parseGroupMapping(string $name, string $json): array
    {
        $result = [];
        foreach (self::decodeMapping($name, $json) as $claimValue => $ids) {
            $result[$claimValue] = self::parseIds("{$name}.{$claimValue}", $ids);
        }

        return $result;
    }

    /**
     * Turns the mapping JSON into "string → list of values". The types inside are not checked here.
     *
     * @return array<string, list<mixed>>
     * @throws InvalidSettingException
     */
    private static function decodeMapping(string $name, string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidSettingException("{$name}: not valid JSON ({$e->getMessage()})", 0, $e);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidSettingException("{$name}: must be an object of the form {\"claim value\": [...]}");
        }

        $result = [];
        foreach ($decoded as $claimValue => $targets) {
            if (!is_array($targets) || !array_is_list($targets)) {
                throw new InvalidSettingException("{$name}.{$claimValue}: value must be an array");
            }
            $result[(string) $claimValue] = $targets;
        }

        return $result;
    }

    /**
     * @param  list<mixed> $names
     * @return list<UserFlag>
     * @throws InvalidSettingException
     */
    private static function parseFlags(string $name, array $names): array
    {
        return array_map(
            static function (mixed $flag) use ($name): UserFlag {
                $parsed = is_string($flag) ? UserFlag::tryFrom($flag) : null;
                if ($parsed === null) {
                    $allowed = implode(', ', array_column(UserFlag::cases(), 'value'));
                    $shown = is_scalar($flag) ? (string) $flag : gettype($flag);
                    throw new InvalidSettingException("{$name}: \"{$shown}\" is not allowed. Allowed: {$allowed}");
                }

                return $parsed;
            },
            $names,
        );
    }

    /**
     * @param  list<mixed> $values
     * @return list<int>
     * @throws InvalidSettingException
     */
    private static function parseIds(string $name, array $values): array
    {
        return array_map(
            static function (mixed $value) use ($name): int {
                if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
                    return (int) $value;
                }
                $shown = is_scalar($value) ? (string) $value : gettype($value);
                throw new InvalidSettingException("{$name}: \"{$shown}\" is not a user group ID (positive integer)");
            },
            $values,
        );
    }

    /**
     * Selects and multi-line texts are stored as {type, value} objects. Takes just the value.
     *
     * @return array<string, mixed>
     */
    private static function unwrap(object $data): array
    {
        return array_map(
            static fn (mixed $v): mixed => is_object($v) && property_exists($v, 'value') ? $v->value : $v,
            get_object_vars($data),
        );
    }

    /** @param array<string, mixed> $raw */
    private static function string(array $raw, string $name): string
    {
        $value = $raw[$name] ?? '';
        if (!is_scalar($value)) {
            throw new InvalidSettingException("{$name}: must be a string");
        }

        return trim((string) $value);
    }

    /** @param array<string, mixed> $raw */
    private static function bool(array $raw, string $name): bool
    {
        return !empty($raw[$name]);
    }

    /**
     * @param array<string, mixed> $raw
     * @throws InvalidSettingException
     */
    private static function intInRange(array $raw, string $name, int $min, int $max): int
    {
        $value = $raw[$name] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            throw new InvalidSettingException("{$name}: must be an integer");
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new InvalidSettingException("{$name}: must be between {$min} and {$max}");
        }

        return $int;
    }

    /**
     * @template T of \BackedEnum
     * @param  array<string, mixed> $raw
     * @param  class-string<T>      $enum
     * @return T
     * @throws InvalidSettingException
     */
    private static function enum(array $raw, string $name, string $enum): \BackedEnum
    {
        $value = self::string($raw, $name);
        $parsed = $enum::tryFrom($value);
        if ($parsed === null) {
            $allowed = implode(', ', array_column($enum::cases(), 'value'));
            throw new InvalidSettingException("{$name}: \"{$value}\" is not allowed. Allowed: {$allowed}");
        }

        return $parsed;
    }
}
