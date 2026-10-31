# Authentication and revocation

Account tokens are accepted only as strings matching the existing 32-character
hexadecimal format. APP authentication, optional APP authentication, SmartRoute
token authentication and subscription account-token resolution share
`UserTokenAuthenticator`. Subscription OTP and TOTP envelopes retain their own
formats and must resolve to a valid account token. Banned accounts cannot fetch
subscriptions.

Web authentication verifies the JWT signature, the registered session and current
account status and roles on every request. Cached identity objects are no longer
used as authentication evidence. Existing signed sessions remain supported when
their original session metadata is present. Newly created sessions also bind to a
fingerprint of the account security fields.

Removing one session immediately revokes it without logging out other sessions.
Session registry changes use a per-account cache lock to avoid overwriting a
concurrent registration or revocation. Failures deny authentication.

`User` model security hooks revoke web sessions when password, password algorithm
or salt, email, roles, banned state or account token changes. Credential/role
changes and a new ban also rotate the account token. APP login and subscriptions
currently share that token: after such a security change, the user must log in
again and update their subscription URL. Routine traffic, expiry and activity
updates do not rotate credentials. Deployment itself does not modify accounts or
rotate their tokens.

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
