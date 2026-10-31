# AVideo-LoginOIDC

English | [日本語（原本）](README.ja.md)

A plugin that adds sign-in with OpenID Connect to [AVideo](https://github.com/WWBN/AVideo). The plugin name is `LoginOIDC`.

It is meant to work with Keycloak and with any other provider that speaks OpenID Connect.

> **This is not an official AVideo plugin.** It is a personal project with no connection to WWBN.

## What it does

- Sign-in with the Authorization Code Flow and PKCE. It supports discovery, so configuration starts from the issuer URL
- Matches users at the provider to AVideo users by `sub`, and creates the user on first sign-in
- Derives AVideo permissions (admin, upload and so on) and user groups from claim values
- Tells the provider when a user signs out of AVideo (RP-Initiated Logout), and signs users out of AVideo when they sign out at the provider (Back-Channel Logout)
- Restricts sign-in with a username and password, with room for an exception to use when the provider is down
- Periodically applies deletions, deactivations and permission changes made at Keycloak to AVideo (optional)
- Sends anonymous visitors to the provider's sign-in automatically (optional)
- Shows configuration mistakes on the admin pages
- Shows its texts in the language AVideo is set to (English and Japanese)

The detailed specification, and the reasons behind it, are in [docs/spec.md](docs/spec.md).

## Status

In development. Everything in scope for v1 of the specification is implemented and has been checked in the development environment. It has not been proven in production yet.

## AVideo versions it was checked with

| AVideo | Commit | Checked on |
|---|---|---|
| v29.3.0 | `12d5c1042` | 2026-10-11 (development environment: MariaDB 12.3, PHP 8.5, Keycloak 26.8.0, the Encoder as of that day) |
| v29.3.0 | `12d5c1042` | 2026-10-27 (installed directly on Ubuntu 26.04: Apache 2.4 + PHP 8.5, MariaDB 12.3, Keycloak 26.8.0, Encoder v8.3.0. Behind an Nginx reverse proxy and oauth2-proxy, with the Encoder going through the same proxy) |

On 2026-10-31 it was also checked that, on a database upgraded from AVideo 14.3 to v29.3.0, existing users can be linked to users at the provider with `cli/link.php`.

Check the plugin again whenever AVideo is updated. It depends on a library bundled with AVideo and on AVideo's hooks.

## Installation

1. Put `LoginOIDC/` from this repository at `plugin/LoginOIDC/` in AVideo
2. In the plugin list of the AVideo admin pages, enable LoginOIDC and create its tables
3. Register a client at the provider. The redirect URI is shown in the plugin's description in the plugin list
4. Enter the issuer, the client ID and the client secret in the plugin settings
5. Open "Check settings" in the plugin list and make sure it finds no problems

For mapping permissions, controlling native sign-in, the Keycloak sync and the other settings, see [docs/spec.md](docs/spec.md).

## Requirements

- PHP 8.1 or later
- No external library is bundled. JWTs are verified with `firebase/php-jwt`, which AVideo ships
- The database can be MariaDB or MySQL

## Documents

| Document | Contents |
|---|---|
| [docs/spec.md](docs/spec.md) | The specification and the design decisions |
| [dev/README.md](dev/README.md) | How to set up the development environment and run the tests |

**The Japanese documents are the originals.** To change a document, edit the Japanese one (`*.ja.md`) first and then translate it into the English one (`*.md`). Where the two disagree, the Japanese one is authoritative.

## License

MIT License. See [LICENSE](LICENSE).

AVideo is a trademark of WWBN.
