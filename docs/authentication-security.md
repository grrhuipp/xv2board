# Authentication and revocation

Account tokens are accepted only as strings matching the existing 32-character
hexadecimal format. Subscription account-token resolution uses
`UserTokenAuthenticator`. APP authentication, optional APP authentication and
SmartRoute use `AppTokenAuthenticator`, which resolves independent, session-bound
APP credentials. Subscription OTP and TOTP envelopes retain their own formats
and must resolve to a valid subscription token. Banned accounts cannot fetch subscriptions.

APP password login still returns a 32-character `token`, preserving the existing
client request/response format. It is a random APP session credential, not the
subscription token. Sync retains the current APP credential. Server-side cache
entries use its SHA-256 hash and bind to a registered signed session; plaintext APP
credentials are not stored in the session registry. Removing that session or
changing its account security state immediately invalidates the APP credential.

The upgrade adds `v2_user.app_legacy_token_disabled`. Existing accounts start at
0 to retain their current APP login until their next password or other security
change. That change sets a durable fence to 1, so the old shared token can no
longer authenticate APP requests even after the cache is cleared. New accounts
default to 1 and use only separate APP sessions. The subscription endpoint never
uses this compatibility fence. The deployment does not log out all existing users.

Web authentication verifies the JWT signature, the registered session and current
account status and roles on every request. Cached identity objects are no longer
used as authentication evidence. Existing signed sessions remain supported when
their original session metadata is present. Newly created sessions also bind to a
fingerprint of the account security fields.

Removing one session immediately revokes it without logging out other sessions.
Session registry changes use a per-account cache lock to avoid overwriting a
concurrent registration or revocation. Failures deny authentication.

`User` model security hooks revoke web sessions when password, password algorithm
or salt, email, roles, banned state or account token changes. Password-only changes
preserve the subscription token and URL while revoking web and APP sessions,
quick-login codes and monitoring grants. Email/role changes and a new ban rotate
the subscription token. Routine traffic, expiry and activity updates do not rotate
credentials. Deployment itself does not rotate existing subscription tokens.

Security-field mutations must use model saves, not query-builder bulk updates
which bypass model hooks. Admin and staff bulk-ban handlers process accounts in
primary-key chunks and execute the hooks. Staff updates additionally restrict and
lock their target to a non-admin, non-staff account within a transaction.

Quick-login codes are single-use, last 60 seconds and bind to current account
security state. Horizon grants last at most six hours and bind to the issuing
administrator session; their use rechecks the live session and administrator
role. Old unbound monitoring grants are not accepted: reopen the monitor from the
admin panel after deployment. Quick-login codes issued immediately before the
deployment must also be requested again. Existing ordinary web sessions and valid
account/subscription tokens retain their formats.

## Verification

Run the focused isolated tests with the project's installed dependencies:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/AuthenticationLifecycleTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/AuthCredentialBoundaryTest.php
```

The lifecycle suite uses an in-memory SQLite database and an array cache. It
covers credential rejection before database access, exact token matching, all
three subscription modes, staff target restrictions, security-state changes,
single-session revocation, bound monitoring grants and one-use login codes.
