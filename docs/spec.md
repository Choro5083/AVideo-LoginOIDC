# Specification

English | [日本語（原本）](spec.ja.md)

> Translated from the Japanese original. Where the two disagree, the Japanese one is authoritative.

## Layers

The features are decided in four layers. This repository covers (1) to (3).

| Layer | What it is | Status |
|---|---|---|
| (1) Acting as an OIDC relying party | How the plugin behaves in the protocol | Settled |
| (2) Integration with AVideo | Creating and matching users, mapping claims to permissions, controlling native sign-in | Settled |
| (3) Provider-specific extensions | Periodic sync through the Keycloak Admin API (optional) | Settled |
| (4) Values chosen by each site | Role names, the provider's URL and so on | Not part of this repository |

**Nothing specific to one environment (host names, realm names and so on) goes into the repository.** All of it comes from the settings.

## (1) Acting as an OIDC relying party

### Basics

| Feature | Supported | Notes |
|---|---|---|
| Discovery (`.well-known/openid-configuration`) | Yes | So that the issuer URL is all that has to be configured |
| Authorization Code Flow | Yes | The only flow |
| PKCE (S256) | Yes | |
| Checking `state` and `nonce` | Yes | |
| Verifying the ID Token | Yes | Signature, `iss`, `aud`, `exp`, `iat`, `nonce` |
| Fetching and caching the JWKS | Yes | Fetched again when a `kid` is not found, to follow key rotation |
| UserInfo endpoint | Yes (optional) | For providers that do not put the needed claims in the ID Token |
| Choosing the scopes | Yes | `openid profile email` by default |
| Implicit and Hybrid Flow | No | Deprecated |
| Refresh tokens | No | After sign-in the user runs on AVideo's session, so there is no need to keep tokens |

### Client authentication

How the plugin proves its own identity to the provider at the token endpoint.

| Method | Supported in |
|---|---|
| `client_secret_basic` | v1 |
| `client_secret_post` | v1 |
| `private_key_jwt` | v2 |

**The method is replaceable by design**, so that adding `private_key_jwt` in v2 touches nothing else.

The methods a provider supports are listed in `token_endpoint_auth_methods_supported` of its discovery document. A method the provider does not support cannot be used.

### Logout and sessions

| Feature | Supported | Notes |
|---|---|---|
| RP-Initiated Logout | Yes | Signing out of AVideo signs out of the provider too |
| Back-Channel Logout | Yes | A notice from the provider ends the AVideo session |
| Front-Channel Logout | No | Often broken by the browsers' third-party cookie restrictions |
| Session Management (iframe) | No | Same reason |

#### How sessions are ended

A session that started through the provider carries a marker (the user, the ID of the provider session, the time of sign-in). Every request checks it against the database, and the user is signed out on the spot when any of these is true.

- The user was disabled, or no longer exists
- That provider session (`sid`) was ended by a back-channel logout
- Every session of the user was ended after this sign-in

**PHP sessions are not looked up by ID and deleted.** AVideo replaces session IDs, so a mapping would go stale. It also keeps the plugin independent of where sessions are stored (files, memcached and so on).

When a back-channel logout notice names a `sid`, only that session ends. Without a `sid`, or when the `sid` is not on record, every session of that person ends.

RP-Initiated Logout is done by catching the request to AVideo's logout (`objects/logoff.php`) at the start of the request, because AVideo has no logout hook. When the provider has no logout endpoint, or discovery fails, only AVideo's own logout happens.

### Number of providers

**One provider.** Combining several providers is treated as the provider's job (Identity Brokering in Keycloak, for example). For social sign-in, AVideo already has plugins.

**A user is identified by the pair of issuer and `sub`.** A `sub` is only unique within one provider. Storing the issuer with it prevents signing someone in to another person's account when the issuer setting is pointed at a different provider by mistake.

### Operation

| Feature | Notes |
|---|---|
| Allowed clock skew | Configurable |
| TLS certificate verification | There is no setting to turn it off. For a private CA, only the path of the CA certificate can be set |
| Providers over http | Refused by default. The issuer and every endpoint must use https. A setting allows http for development |
| Error display | The user's page shows only a reference to find the log line. The details of a failure (the provider's response, what failed to verify) go to the log only |
| Language of the log | English (ASCII). Apache's error log escapes anything that is not ASCII, which makes Japanese unreadable |
| Rate limit | Sign-in starts and callbacks from one address are limited per 5 minutes (60 by default, configurable, 0 to disable). Behind a reverse proxy, everyone counts as one address unless AVideo is configured to trust the proxy |
| Debug log | Token contents are never logged |

### Implementation approach

**The relying-party logic is written here. Only JWT signature verification and JWKS handling use `firebase/php-jwt`, which AVideo ships.**

- A bundled OIDC client library would be loaded in the same PHP process as AVideo's `vendor/`, and versions of shared dependencies could clash
- The Keycloak provider of Hybridauth, which AVideo ships, has no discovery, ID Token verification, nonce or PKCE, so it is not used
- Signature verification is not reimplemented

**The SQL the plugin issues is written to run on both MariaDB and MySQL.** AVideo mainly targets MariaDB, but some sites run MySQL. Syntax specific to either (`ADD COLUMN IF NOT EXISTS` and the like) is avoided, and both are tested.

Because the plugin depends on the version of `firebase/php-jwt` in AVideo, it has to be checked whenever AVideo is updated (see "AVideo versions it was checked with" in the README).

### Language of the pages

The plugin shows its texts in the language AVideo is set to. It uses AVideo's translation mechanism, where the English text is the key and a per-language array replaces it.

| Item | Specification |
|---|---|
| Default language | English (United States). The texts written in the code are shown as they are |
| Adding a language | Put `LoginOIDC/locale/<language code>.php` in place (for example `ja_JP.php`). The codes are the ones of AVideo's own `locale/` |
| Which language is used | The one AVideo decided on (the user's choice, the site default). Without an exactly matching file, a file for the same language is used (`ja_JP.php` for `ja`) |
| Translated | Names and descriptions of settings, select options, the description in the plugin list, the settings check page, the sign-in button, error pages |
| Not translated | The log, so that it reads the same whatever the user's language |

Tests detect missing translations, translations left over for texts that are gone from the code, and placeholders that differ between languages.

### Settings check

AVideo has no hook for a plugin to validate its settings when they are saved. Wrong settings (a typo in the JSON, for example) are saved as they are. The plugin finds the problems afterwards and tells the admin.

| Where | What |
|---|---|
| Description in the plugin list | The number of problems visible from the values alone, and the first error. The list is reloaded after saving, so a bad save shows right away |
| Check page ("Check settings" in the list) | The above, plus problems that need the provider (it cannot be reached, it does not support the client authentication method and so on). Admins only |

Examples of what is checked: the shape of the issuer, `openid` among the scopes, a mapping that points at a flag that is not managed, a managed flag that nothing grants (it is removed from everyone), a user group that does not exist, an empty allowlist (no way in when the provider is down), an Encoder address that cannot be read, an Encoder address that covers a trusted reverse proxy.

While the settings cannot be read, no sign-in is accepted. Sessions that are already signed in continue.

### Storing the secret

`clientSecret` is a setting of AVideo's "encrypted" type. Saved from the admin page, it is stored encrypted in the database (the key is in AVideo's configuration file). The secret does not end up in clear text in database dumps and backups.

### Failures inside the hooks

AVideo calls the plugin from two hooks. What happens on an unexpected failure is decided per hook.

| Hook | On failure | Why |
|---|---|---|
| Start of a request (`getStart`) | Log it and let AVideo carry on | It runs for every request. An exception escaping from it takes the whole site down |
| A sign-in succeeded (`onUserSignIn`) | Cancel the sign-in | Letting it through would be a way around the native sign-in control |

## (2) Integration with AVideo

### Creating and matching users

| Item | Specification |
|---|---|
| Matching key | `(issuer, sub) → users.id` is stored in a table of the plugin. Unique constraints on both `(issuer, sub)` and `users_id` keep it one to one |
| When there is no link | A new user is created (just in time). This can be turned off, and then only users linked in advance can sign in |
| Linking to existing AVideo users | **Never automatic**, not even when the e-mail address is the same. An admin links them with the CLI (see "Linking by hand" below) |
| Username | Made from a claim (`preferred_username` by default, configurable). Unusable characters are removed, it is cut to 45 characters, and a number is appended on a clash. **It never changes after creation** |
| Attributes updated on every sign-in | Name, e-mail address, `emailVerified` (from the `email_verified` claim). Each can be turned off |
| Password | A long random value that cannot be guessed and is known to nobody (`users.password` is NOT NULL) |
| Users disabled in AVideo (`status = 'i'`) | Refused, even when the provider authenticates them |
| Default user group of new users | As AVideo is configured (`userDefaultUserGroup`) |

**AVideo's mechanism for social sign-in (`User::createUserIfNotExists`) is not used.** It signs the visitor in as the existing user whenever the username matches, so anyone who can make their e-mail address or display name at the provider equal to someone else's gets into that person's account.

**The username never changes** so that channel URLs and the like stay the same when a user is renamed at the provider. Matching uses `sub`, so a username that differs from the provider's is not a problem.

#### Linking by hand

To link an existing AVideo user to a user at the provider, an admin checks the `sub` and uses the CLI.

```
php plugin/LoginOIDC/cli/link.php <username> <sub>     link
php plugin/LoginOIDC/cli/link.php --unlink <username>  remove the link
php plugin/LoginOIDC/cli/link.php --list               list the links
```

Removing a link ends that user's sessions that came through the provider, at their next request. On a site with user creation turned off (`createUsers` off), only users linked with this CLI can sign in.

### Mapping claims to permissions

This concerns AVideo's flags `isAdmin`, `canUpload`, `canStream`, `canViewChart` and `canCreateMeet`.

| Item | Specification |
|---|---|
| Naming the claim | A dot-separated path (for example `resource_access.avideo.roles`). The value may be an array or a string |
| Writing the mapping | The settings hold a table of "claim value → flags to grant" |
| Several values match | Every matching flag is granted (OR) |
| Flags the plugin manages | Only the flags chosen in the settings are overwritten from the claims on every sign-in. The others are left alone |
| Claim missing or empty | Treated as an empty list. Every managed flag becomes 0 |
| Where claims are read from | The ID Token and UserInfo together. The access token is not read |
| Requirement to sign in | Optionally: "refuse the sign-in unless this claim contains this value" |

**The managed flags are selectable** so that each site can split what the provider decides from what is managed one by one in AVideo's admin pages. For example, admins are decided at the provider while `canStream` is granted individually in AVideo.

**The access token is not read** because it is meant for a resource server. A client is not supposed to interpret it. With some providers, roles have to be configured to appear in the ID Token or UserInfo.

### Mapping claims to user groups (optional)

AVideo uses user groups to decide who can see a video. The mechanism is the same as for flags.

| Item | Specification |
|---|---|
| Writing the mapping | The settings hold a table of "claim value → AVideo user groups" |
| Naming a user group | By ID, so that renaming a group in AVideo does not break the mapping. An ID that does not exist is reported as an error by the settings check |
| Groups the plugin manages | Only the groups chosen in the settings are overwritten on every sign-in. The others are left alone |
| The default group | When the default group of new users (`userDefaultUserGroup`) is among the managed ones, the claims overwrite it |

### Automatic redirect for anonymous visitors

| Item | Specification |
|---|---|
| Default | Off. Turned on in the settings |
| Applies to | Page views (HTML) only. APIs, JSON, video files and requests for the Encoder are never redirected |
| Afterwards | The user returns to the page they asked for |
| Exception | The native sign-in page stays reachable (as the way in when the provider is down) |

### Controlling native sign-in

**AVideo's `disableNativeSignIn` (CustomizeUser) only removes the sign-in form from the page.** The sign-in API (`objects/login.json.php`) still accepts a username and password. To restrict it, the plugin has to refuse on the server.

| Item | Specification |
|---|---|
| Mode | One of: no restriction / deny users linked to the provider / allow only the users in the allowlist |
| How | Decided in `onUserSignIn`. A sign-in that is not allowed is signed out immediately and logged |

**A user linked to the provider is never restored from the cookie, in any mode.** AVideo can sign a user in again from a cookie when the session is gone. Allowing that would keep a device with the cookie signed in after a logout or a deactivation at the provider. When the session is gone, the user signs in through the provider again (with a live provider session, that takes no action from the user).

AVideo has no hook before a sign-in. The only one available is `onUserSignIn`, called right after it succeeded, so the sign-in is cancelled just after it happened. The sign-in API then answers that the user is not signed in (`isLogged: false`).

Sessions of users linked to the provider get a marker even when they start with a native sign-in. Without it they would escape deactivation.

### Sign-in from the Encoder

When a user uploads from AVideo's pages, AVideo hands the Encoder the user's name and a time-limited token (valid for 7 days), and the Encoder uses them to sign in to AVideo as the uploader. **Forbidding the uploader's native sign-in breaks uploads.**

| Item | Specification |
|---|---|
| Encoder exception | When the request comes from a configured Encoder IP address, the native sign-in control does not apply |

The origin is determined **by the same rule as AVideo itself** (AVideo's `getRealIpAddr()`).

- By default it is the connecting address (`REMOTE_ADDR`). Forwarding headers (`X-Real-IP`, `X-Forwarded-For`) are ignored, because the sender controls them
- Only when the connecting address is a reverse proxy listed in AVideo's `$global['trustedProxies']` (`videos/configuration.php`), the address that proxy passed on in the forwarding headers is used
- AVideo's own sign-in rate limit follows the same rule, so the Encoder exception and the rate limit never look at different addresses

**Behind a reverse proxy, the Encoder's requests go through the proxy too.** To sign in to AVideo, the Encoder uses the URL AVideo named when the user came from AVideo (the public URL). The Encoder's setting `forceStreamerSiteURL` redirects only the requests that deliver the converted video, not the sign-in (checked with AVideo Encoder v8.3.0). So a path that bypasses the proxy cannot be assumed.

Conditions for running behind a reverse proxy:

| Condition | Why |
|---|---|
| The proxy is listed in `$global['trustedProxies']` | Otherwise everyone's origin is the proxy's address |
| The proxy **replaces** `X-Real-IP` and `X-Forwarded-For` instead of appending to them | AVideo uses the first address in these headers. With a proxy that appends to what the user sent, the user can forge the origin |
| `encoderAddresses` lists the Encoder's own address, **never the proxy's** | Listing the proxy exempts everyone who comes through it. The settings check reports an `encoderAddresses` that covers a trusted proxy as an error |
| Authentication in front of the proxy (oauth2-proxy and the like) lets the Encoder's requests through | The Encoder is not the user's browser and cannot pass the proxy's authentication |

AVideo's `isAVideoEncoder()` decides by the User-Agent, which can be forged, so it is not used.

**A dedicated account for the Encoder was rejected.** The videos would belong to that account, not to the uploader.

### Mobile app

**Out of scope for v1.** A user linked to the provider cannot sign in from the mobile app.

- Supporting it needs a separate sign-in flow for the app, which is a large piece of work
- Whether a third-party plugin can add a sign-in method to AVideo's mobile app (provided by WWBN) has not been checked

It is a candidate for v2 or later. Even in v1, creating and matching users and applying permissions are kept apart from the web sign-in flow, so that another entry point can call them.

## (3) Provider-specific extensions

### Keycloak sync

Applies changes at the provider to AVideo, through the Keycloak Admin API. It is part of v1. It is optional: without it, (1) and (2) work on their own.

#### What is covered

| Item | Specification |
|---|---|
| Who | Only users linked to the provider. Users that are not linked, and the users in the native sign-in allowlist, are not touched |
| Creating users | Never. Users are created on first sign-in only |
| Deleted (404 from the API) | Disabled in AVideo (`status = 'i'`). Never deleted |
| Disabled (`enabled = false`) | Disabled in AVideo |
| Enabled again | Only users the sync disabled are re-enabled. Users an admin disabled by hand stay disabled (who disabled a user is recorded) |
| Roles or groups changed | The managed flags and user groups are recomputed with the same mapping as at sign-in |
| Name or e-mail address changed | Updated with the same settings as at sign-in |
| Sessions of disabled users | End at their next request (see "How sessions are ended") |

**The plugin goes no further than disabling the user. Videos are never touched.** Changing whether a video is public would lose the original state for when the change has to be undone. What happens to the videos of a disabled user is left to the admin or to other tools.

#### Reading the values permissions derive from

At sign-in the values come from a claim path, but the Admin API returns the assignments themselves. The sync needs its own setting (`syncRoleSource`) for which kind of assignment to read. What it reads is treated as the sign-in claim (`rolesClaimPath`), and the same mapping decides the flags and user groups.

**Choose the kind that gives the same values as the sign-in claim.** If they differ, sign-in and sync assign different permissions.

The permissions the sync client needs (roles of `realm-management`) depend on what is read. Checked with Keycloak 26.8.

| Kind | Permissions needed | Notes |
|---|---|---|
| Groups (default) | `view-users`, `query-users` | Reads only the users' data and their groups |
| Realm roles | The above plus `view-realm` | |
| Client roles | The above plus `view-clients`, `query-clients` | **`view-clients` can read the configuration of every client in the realm, secrets included** |

**The default is groups.** The `view-clients` needed for client roles means that a leaked sync secret exposes the secret of every client in the realm. When permissions are handed out through groups, and the groups are put into the claims as well (with a group mapper), sign-in and sync work from the same values and the sync client needs the least permission.

For groups, both the name and the path count as values, because which of them the provider puts in the claim depends on its mapper.

The `sub` of the ID Token is assumed to be the Keycloak user ID (Keycloak's default).

#### Authenticating to the Admin API

- A client dedicated to the sync, authenticating with its service account (client credentials). It is separate from the sign-in client
- The permissions are the ones in the table above: only what the chosen kind needs
- **The secret is never stored in the AVideo database.** It is passed to the CLI as a file path (`syncClientSecretFile`) or in the environment variable `LOGINOIDC_SYNC_CLIENT_SECRET`

#### Running it

```
php plugin/LoginOIDC/cli/sync.php [--dry-run] [--max-disable-ratio=<0-100>]
```

- Run it from cron, at an interval the site chooses. Run it as the same user as the web server, because the two share cache files
- A lock keeps two runs from overlapping
- `--dry-run` only shows what would happen. Where a real run would stop (see the safeguards below), it does not stop and shows the reason instead
- Every run writes a summary (disabled, re-enabled, sessions ended, updated) to standard output and to the log
- Exit codes: 0 = success, 1 = failure, 2 = usage error, 3 = another sync is running

#### Safeguards

- **A failing API (timeout, 5xx, authentication error) changes nothing.** A user is disabled only on a 404 or `enabled = false`
- **When one run would disable more than a set share of the users, it stops without changing anything.** This prevents disabling everyone when, for example, the settings point at another realm by mistake. When the deactivations are intended, `--max-disable-ratio` raises the limit for that one run
- **A user who no longer meets the requirement to sign in has their sessions ended.** The account is not disabled (meeting the requirement again lets them back in)
