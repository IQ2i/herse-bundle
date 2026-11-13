# Herse configuration reference

Source of truth for the installed version: `bin/console config:dump-reference iq2i_herse` and `bin/console debug:config iq2i_herse`. This file is the commented version.

## Contents
- Full configuration tree
- `users`
- `mailer`
- Features: `password_policy`, `login_throttling`, `trusted_devices`, `password_change_notification`, `two_factor`
- Validation errors of the configuration
- Services and public API
- Events
- Console commands
- Overridable templates and translations

## Full configuration tree

Every feature node accepts the shortcut `feature: true` (same as `{ enabled: true }`) and `feature: false`. Defaults are shown.

```yaml
iq2i_herse:
    users:                                  # keyed by class name, every class must exist
        App\Entity\User:
            type: shop                      # required, unique across the classes
            email: email                    # required, property path (PropertyAccess), e.g. customer.email
            id: id                          # property path of the identifier
            password: password              # Doctrine field of the hashed password
            locale: ~                       # property path of the user locale
            plain_password:                 # a string is the property: plain_password: plainPassword
                property: ~
                groups: [Default]           # validation groups of the constraint

    firewalls:                              # by firewall name: their security configuration is checked at compilation
        main:
            path_prefix: ''                 # prefix of the routes of the bundle on the firewall, e.g. /admin
            home: ~                         # where the screens lead once done, a path or a route name; the path prefix by default
            layout: ~                       # template the screens extend on this firewall, ui.layout otherwise
            theme: ~                        # directory of templates replacing the ones of the bundle on this firewall
            login_throttling: true          # require the limiter of the bundle while login_throttling is enabled
            two_factor: true                # generate the second factor routes and check the firewall while two_factor is enabled
            verify: true                    # false disables every check of the firewall

    ui:
        layout: '@IQ2iHerse/layout.html.twig'   # template the screens extend, with a "body" block

    mailer:
        sender: ~                           # required as soon as a feature sending emails is enabled
        sender_name: ~
        app_name: ~                         # "app_name" in the templates, issuer of the TOTP

    password_policy:
        enabled: false
        min_length: 12
        max_length: 4096                    # PasswordHasherInterface::MAX_PASSWORD_LENGTH, must be >= min_length
        required_character_types: 4         # 0 to 4 among lowercase, uppercase, digit, special
        check_compromised: false            # symfony/http-client
        hash_guard: true                    # refuse to hash a password breaking the policy; accepts %env(bool:...)%
        forms:
            auto: true                      # check every PasswordType with autocomplete="new-password"
            help: true                      # describe the policy in the help of these fields

    login_throttling:
        enabled: false
        identifier_and_ip: { limit: 5, interval: '1 minute' }
        ip:                { limit: 25, interval: '1 minute' }
        identifier:        { enabled: false, limit: 20, interval: '15 minutes' }
        cache_pool: cache.rate_limiter
        lock_factory: auto                  # a service id, auto (lock.factory if it exists), or null

    trusted_devices:
        enabled: false
        firewalls:                          # by firewall name, the ones of iq2i_herse.firewalls by default
            main: ~
            admin:
                cookie_name: ~              # herse_device_<firewall>
                lifetime: '30 days'
        store_ip: true
        notify_first_device: true
        notify_before_second_factor: false
        revoke_on_password_change: false
        screen: true                        # the declared firewalls that are watched get the "my devices" screen

    password_change_notification:
        enabled: false
        notify_on_other_user: true
        notify_on_console: true

    two_factor:
        enabled: false
        method: ~                           # email | totp, required when enabled
        required: true                      # totp: a user without authenticator app is sent to the enrollment screen
        reset_role: ~                       # totp: the role allowed to reset the second factor of a user on the screen of the bundle
        trusted_devices: false
        trusted_lifetime: '30 days'
        throttling:
            enabled: true
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
            lock_factory: auto
```

Intervals are relative date formats (`'1 minute'`, `'15 minutes'`, `'7 days'`); an invalid one fails the compilation.

## `users`

- A user is referred to by `UserReference` (`type` + `identifier`), never by a Doctrine relation. A device trusted on `shop` is not trusted on `admin`.
- `email` and `locale` are property paths: `customer.email` reads `getCustomer()->getEmail()`. When the address is missing or invalid, no email is sent and a warning is logged.
- `password` must be a field mapped by Doctrine, otherwise `password_change_notification` throws a `LogicException` on the first update.
- `plain_password` requires `password_policy` to be enabled, otherwise the configuration is rejected.

## `firewalls`

- The bundle never writes `security.yaml`. It checks what the security extension built from it, and the compilation fails with the lines to add when a declared firewall misses something a feature relies on.
- With `login_throttling`: the firewall must use `iq2i_herse.login_throttling.limiter`, without the native `max_attempts` or `interval` next to it (Symfony ignores them once a limiter is set).
- With `two_factor`: the firewall must have `two_factor` with `auth_form_path: iq2i_herse_<firewall>_two_factor_form`, `check_path: iq2i_herse_<firewall>_two_factor_check` and `enable_csrf: true`, a `logout`, a pattern covering `<path_prefix>/2fa`, and no broader `access_control` rule before `{ path: ^<path_prefix>/2fa, roles: IS_AUTHENTICATED_2FA_IN_PROGRESS }`.
- The routes are imported with `{ resource: ., type: iq2i_herse }` in `config/routes/`: per firewall, `iq2i_herse_<firewall>_two_factor_form` (`<path_prefix>/2fa`) and `iq2i_herse_<firewall>_two_factor_check` (`<path_prefix>/2fa_check`).
- `login_throttling: false` or `two_factor: false` leaves a firewall out of a check, `verify: false` out of every check.
- They are the default of `trusted_devices.firewalls`.

## `mailer`

Shared by the three emails: new device, password changed, two-factor code (email method). Needs `symfony/mailer`, `symfony/twig-bundle`, `symfony/translation` and `framework.mailer`. With Messenger routing the emails, the emails of the bundle follow. A sending error is logged and never fails a login or a password change.

## `password_policy`

- Applied automatically to the `plain_password` property of the declared classes, in the declared groups.
- **Hash guard**: `UserPasswordHasherInterface` is decorated, so hashing a password that breaks the policy throws `PasswordPolicyViolationException` (`getMessages()`, never the password) from any code path, the password updater of Sylius included. Local rules only (no data breach lookup), not on the rehash at login. Disable it with `hash_guard: false` (typically under `when@test`, and `when@dev` for weak fixtures) or suspend it with `PasswordPolicyGuard::withoutGuard(callable)`. It is a net: validate first so that the person gets a message, not an exception.
- **Forms** (needs `symfony/form`): every `PasswordType` with `autocomplete="new-password"` is checked on submit, mapped or not, whatever the validation groups; only the first field of a `RepeatedType`; never the login field. Field option `password_policy: true|false` forces or removes it. The help of the field describes the policy unless it has one (`forms.help`). Do not also add the constraint to such a field: each rule would be reported twice. `IQ2i\HerseBundle\Form\Type\PasswordPolicyType` is a ready-made repeated field.
- **Console**: `$question->setValidator($passwordQuestionValidator)` with `IQ2i\HerseBundle\Console\PasswordQuestionValidator`.
- **Code**: `IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker::violations($password)` / `messages($password)`. `PasswordPolicyDescriber::rules()` / `summary()` and the Twig function `herse_password_policy()` describe the policy.
- By hand: `#[IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy]` (no argument, it reads the configuration; optional `groups`) on a DTO or an API payload.
- Models that are not yours: a validation mapping file in `config/validator/`, see `docs/sylius.md`.
- A special character is anything that is neither a letter nor a digit. Each broken rule has its own message (domain `validators`, English and French) and its own error code constant (`TOO_SHORT_ERROR`, `TOO_LONG_ERROR`, `MISSING_LOWERCASE_ERROR`, `MISSING_UPPERCASE_ERROR`, `MISSING_DIGIT_ERROR`, `MISSING_SPECIAL_CHARACTER_ERROR`, `NOT_ENOUGH_CHARACTER_TYPES_ERROR`, `INVALID_CHARACTERS_ERROR`).
- Only checks a password when it is set, never at login: existing passwords stay valid.

## `login_throttling`

- Three limits scoped to the firewall: `identifier_and_ip`, `ip` and, optionally, `identifier`. The identifier limit stops a distributed attack on one account but lets a third party lock that account for a while.
- Plugged in per firewall: `login_throttling: { limiter: iq2i_herse.login_throttling.limiter }` in `security.yaml`. One service serves every firewall. Declare the firewalls in `iq2i_herse.firewalls` to have a missing line fail the compilation.
- The IP is only right behind a proxy when `framework.trusted_proxies` is set. Use a shared `cache_pool` on several servers.
- A successful login clears the identifier limits, never the IP one.
- Needs `symfony/rate-limiter`.

## `trusted_devices`

- Needs Doctrine ORM and DoctrineBundle, the mailer packages and `mailer.sender`. Entity `IQ2i\HerseBundle\Entity\TrustedDevice`, table `iq2i_herse_trusted_device`, default entity manager: generate a migration.
- `firewalls` defaults to the ones of `iq2i_herse.firewalls`; one firewall at least is required. Cookie `herse_device_<firewall>` (override with `cookie_name`: letters, digits, `_`, `.`, `-`), `HttpOnly`, `Secure`, `SameSite=Lax`. `lifetime` is the inactivity after which a device is forgotten.
- `store_ip: false` keeps no IP (personal data). The raw User-Agent is never stored, only a label (`Firefox (macOS)`).
- `notify_first_device: false` registers the first device of a user silently (useful at roll-out).
- `notify_before_second_factor: true` warns as soon as the password is accepted from an unknown device (with `two_factor`).
- `revoke_on_password_change: true` revokes the devices of a user whose password changes, keeping the browser of the owner who changed it. Requires `password_change_notification`.
- `enabled` must be a literal boolean: the Doctrine mapping is added before environment variables are resolved.
- Purge: `bin/console iq2i:herse:purge-trusted-devices [--dry-run]`.
- "My devices" screen, on each firewall both declared in `iq2i_herse.firewalls` and watched: `iq2i_herse_<firewall>_devices` (`<path_prefix>/devices`), `_device_revoke` (POST `<path_prefix>/devices/{id}/revoke`), `_devices_revoke_others` (POST). CSRF protected, own devices only, closed during an impersonation. Import the routes (`{ resource: ., type: iq2i_herse }`) and link to it; override or embed `devices/_list.html.twig`. Never write a devices controller or template; `screen: false` only for a project keeping its own.

## `password_change_notification`

- Detected by Doctrine on the `password` field, sent after a successful flush: covers profile pages, reset links, administrators and console commands. No table.
- `notify_on_other_user: false` silences changes made by another authenticated user (administrator, impersonation). `notify_on_console: false` silences changes made outside a web request.
- Sends nothing on account creation, on the first password (empty previous value), on a removed password, on an automatic rehash during login, or while suspended (`PasswordChangeNotification::withoutNotification()`).
- Not detected: DQL `UPDATE`, raw SQL. A rolled back outer transaction does not cancel the email.

## `two_factor`

- Bridge to `scheb/2fa-bundle` (its bundle must be registered in `config/bundles.php`). Packages: `scheb/2fa-email` or `scheb/2fa-totp` according to `method`, `scheb/2fa-trusted-device` with `trusted_devices: true`, `symfony/rate-limiter` for the throttling.
- Defaults injected under `scheb_two_factor`, which the project configuration always overrides: email code of 6 digits sent by the bundle; TOTP issuer `mailer.app_name`; code form `@IQ2iHerse/two_factor/form.html.twig`; trusted device manager of the bundle. No `scheb_2fa.yaml` is needed.
- User entity: `implements Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface` + `use IQ2i\HerseBundle\TwoFactor\Model\EmailTwoFactorTrait` (column `email_auth_code`, every user has the second factor), or `implements IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface` + `use TotpTwoFactorTrait` (column `totp_secret`, enabled once a secret is set). Then a migration.
- The email code goes to `getEmailAuthRecipient()` of the user class, or to the `email` property path when it is empty (the trait).
- Screens: they extend `ui.layout` (or the `layout` of the firewall), which needs a `body` block. Override or embed a partial (`two_factor/_form.html.twig`, `two_factor/_setup.html.twig`, `two_factor/_reset.html.twig`, `devices/_list.html.twig`) in `templates/bundles/IQ2iHerseBundle/` to change the markup, or give a firewall its own `theme` directory holding templates of the same names (plus optional `security.html.twig` / `account.html.twig` frames defining the blocks `herse_title` and `herse_content`).
- Twig functions: `herse_two_factor_reset_path(user)` (null when nothing to reset or without the role), `herse_password_policy()`.
- Tests: `IQ2i\HerseBundle\Test\TwoFactorTestTrait` (`totpCode($secret)`, `passSecondFactor($client, $code)`); the email code is `getEmailAuthCode()` of the user read afresh.
- `security.yaml` stays written by the project and is checked, see `firewalls`.
- TOTP enrollment: route `iq2i_herse_<firewall>_two_factor_setup` (`<path_prefix>/second-factor/setup`), provided with its screen. With `required: true` (default) a user of a declared firewall without a secret is redirected to it from every page; with `false`, link to it. The secret is stored once a first code is accepted. QR code with `bacon/bacon-qr-code` (the key alone without it). Leads to `firewalls.<name>.home`. Never write an enrollment controller, a "pending secret" class or a redirect listener.
- `trusted_devices: true` requires the `trusted_devices` feature, with the firewall watched there. The cookie and lifetime options of scheb then have no effect; `trusted_lifetime` counts from the verification and is not extended by logins.
- `throttling`: wrong second factors per user and firewall, whatever the IP. `throttling: false` removes it, only when the project brings its own. The login throttling cannot replace it.
- `enabled`, `method` and `trusted_devices` must be literal values, not environment variables.
- No backup codes. A lost authenticator app is reset (the user enrolls again, their trusted devices are revoked): `bin/console iq2i:herse:reset-two-factor <email> [--type=]`; with `reset_role`, the screen `iq2i_herse_<firewall>_two_factor_reset` (`<path_prefix>/second-factor/reset/{type}/{id}`, optional `back` path) for the users having that role; or `IQ2i\HerseBundle\TwoFactor\TwoFactorResetter::reset($user, by: $admin)`. With EasyAdmin, inject `IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction` in the CRUD controller and add `->create()` to its actions. Never write a reset command, service or controller.

## Validation errors of the configuration

The compilation fails with a message naming the fix:

- `iq2i_herse.users: class "…" does not exist.` / `the type "…" is used by several classes`.
- `iq2i_herse.users: "plain_password" requires iq2i_herse.password_policy to be enabled.`
- `iq2i_herse.two_factor.method is required: "email" or "totp".`
- `iq2i_herse.two_factor.trusted_devices requires iq2i_herse.trusted_devices to be enabled.`
- `iq2i_herse.two_factor requires … Try running "composer require …"`, and the same for each feature missing a package.
- `iq2i_herse.password_policy: max_length must be greater than or equal to min_length.`
- `iq2i_herse.firewalls: the firewall "…" is not declared in the security configuration.`
- `The firewall "…" does not use the login limiter of Herse.`, followed by the lines to add to `security.yaml`.

## Services and public API

Public, safe to inject or call:

| Service / class | Use |
|---|---|
| `IQ2i\HerseBundle\TrustedDevice\TrustedDevices` | `list($user, ?$firewall)` (not expired, last used first), `isCurrent($device)`, `revoke($user, $deviceId)` (false when not owned), `revokeAll($user, keepCurrent: true)` (returns the count). The only way to revoke: it also clears the cookie. |
| `IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification` | `withoutNotification(callable)`, `disable()` / `enable()` (nestable), `isEnabled()`. A suspension ends with the request or message. |
| `IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker` | `violations($password, checkCompromised: true)`, `messages(...)`: a plain password against the policy. |
| `IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyGuard` | `withoutGuard(callable)`, `disable()` / `enable()` (nestable), `isEnabled()`: suspends the hash guard. A suspension ends with the request or message. |
| `IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyDescriber` | `rules(?$locale)`, `summary(?$locale)`: the policy, translated, for a help text. |
| `IQ2i\HerseBundle\Console\PasswordQuestionValidator` | Validator of a console question asking for a new password. |
| `IQ2i\HerseBundle\TwoFactor\TwoFactorResetter` | `reset($user, by: null)`: removes the authenticator app of a user and revokes their trusted devices. With `method: totp`. |
| `IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction` | `create()`: the EasyAdmin action linking to the reset screen. With `reset_role` and EasyAdmin. |
| `IQ2i\HerseBundle\User\UserReference` | `type`, `identifier`, `equals()`. |
| `iq2i_herse.login_throttling.limiter` | The limiter to give to the `login_throttling` option of each firewall. |
| `iq2i_herse.user_resolver`, `iq2i_herse.mailer` | Internal wiring, do not depend on them. |

Everything marked `@internal` (repository, listeners, mailer, throttlers) may change without notice.

## Events

All in `IQ2i\HerseBundle\Event`, dispatched with the Symfony event dispatcher.

| Event | Properties | Notes |
|---|---|---|
| `LoginThrottledEvent` | `limit` (`LoginThrottlingLimit`: `IdentifierAndIp`, `Ip`, `Identifier`), `identifier`, `ip`, `firewall` | Once per window and limit, when a failed attempt exhausts it. Never on a successful login. |
| `NewDeviceEvent` | `reference`, `user`, `firewall`, `label`, `ip`, `createdAt` | After the device is registered. `cancel()`, `isCancelled()`, `getEmail()`, `setEmail(?TemplatedEmail)`. Dispatched even with `notify_first_device: false` (email null). |
| `PasswordChangedEvent` | `reference`, `user`, `context` (`PasswordChangeContext`: `Self`, `OtherUser`, `Console`), `changedAt` | Before the email is sent. `cancel()`, `isCancelled()`, `getEmail()`, `setEmail(TemplatedEmail)`. |
| `TrustedDevicesRevokedEvent` | `reference`, `count` | After devices of a user were revoked (screen, service, reset of the second factor, password change). Not by the purge. |
| `TwoFactorSucceededEvent` | `reference` (null if the class is not declared), `user`, `firewall`, `ip` | Relays scheb. Never carries the code. |
| `TwoFactorFailedEvent` | same | Relays scheb. |
| `TwoFactorThrottledEvent` | same | Once, when the limit is reached. |
| `TwoFactorEnabledEvent` | same | Once a user enrolled an authenticator app on the screen of the bundle. Never carries the secret. |
| `TwoFactorResetEvent` | `reference`, `user`, `by` (who did it, null from the console) | After the authenticator app of a user was removed. |

`TrustLevel` (`Known`, `Verified`) is the trust of a device: `Verified` means it passed a second factor and was trusted by the user.

## Console commands

`iq2i:herse:purge-trusted-devices` deletes the expired trusted devices; `--dry-run` only counts them. Registered only with `trusted_devices` enabled. Schedule it (cron, Symfony Scheduler), for instance daily.

`iq2i:herse:reset-two-factor <email> [--type=]` removes the authenticator app of a user. Registered with `two_factor.method: totp` and Doctrine.

## Overridable templates and translations

- Templates in `templates/bundles/IQ2iHerseBundle/email/`: `layout.html.twig` (extended by the others), `new_device.html.twig`, `password_changed.html.twig`, `two_factor_code.html.twig`. Referenced as `@IQ2iHerse/email/…`.
- Translations: domain `iq2i_herse` (emails) and `validators` (password policy), English and French. Override a message by defining the same key in the project catalogue.
