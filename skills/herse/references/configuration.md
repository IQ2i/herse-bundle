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
- Console command
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

    login_throttling:
        enabled: false
        identifier_and_ip: { limit: 5, interval: '1 minute' }
        ip:                { limit: 25, interval: '1 minute' }
        identifier:        { enabled: false, limit: 20, interval: '15 minutes' }
        cache_pool: cache.rate_limiter
        lock_factory: auto                  # a service id, auto (lock.factory if it exists), or null

    trusted_devices:
        enabled: false
        firewalls:                          # by firewall name, required when enabled
            main: ~
            admin:
                cookie_name: ~              # herse_device_<firewall>
                lifetime: '30 days'
        store_ip: true
        notify_first_device: true
        notify_before_second_factor: false
        revoke_on_password_change: false

    password_change_notification:
        enabled: false
        notify_on_other_user: true
        notify_on_console: true

    two_factor:
        enabled: false
        method: ~                           # email | totp, required when enabled
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

## `mailer`

Shared by the three emails: new device, password changed, two-factor code (email method). Needs `symfony/mailer`, `symfony/twig-bundle`, `symfony/translation` and `framework.mailer`. With Messenger routing the emails, the emails of the bundle follow. A sending error is logged and never fails a login or a password change.

## `password_policy`

- Applied automatically to the `plain_password` property of the declared classes, in the declared groups.
- By hand: `#[IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy]` (no argument, it reads the configuration; optional `groups`) on a DTO, an API payload or a command.
- Form: `IQ2i\HerseBundle\Form\Type\PasswordPolicyType`, a `RepeatedType` of `PasswordType` fields carrying the constraint, with `autocomplete="new-password"` (needs `symfony/form`).
- Models that are not yours: a validation mapping file in `config/validator/`, see `docs/sylius.md`.
- A special character is anything that is neither a letter nor a digit. Each broken rule has its own message (domain `validators`, English and French) and its own error code constant (`TOO_SHORT_ERROR`, `TOO_LONG_ERROR`, `MISSING_LOWERCASE_ERROR`, `MISSING_UPPERCASE_ERROR`, `MISSING_DIGIT_ERROR`, `MISSING_SPECIAL_CHARACTER_ERROR`, `NOT_ENOUGH_CHARACTER_TYPES_ERROR`, `INVALID_CHARACTERS_ERROR`).
- Only checks a password when it is set, never at login: existing passwords stay valid.

## `login_throttling`

- Three limits scoped to the firewall: `identifier_and_ip`, `ip` and, optionally, `identifier`. The identifier limit stops a distributed attack on one account but lets a third party lock that account for a while.
- Plugged in per firewall: `login_throttling: { limiter: iq2i_herse.login_throttling.limiter }` in `security.yaml`. One service serves every firewall.
- The IP is only right behind a proxy when `framework.trusted_proxies` is set. Use a shared `cache_pool` on several servers.
- A successful login clears the identifier limits, never the IP one.
- Needs `symfony/rate-limiter`.

## `trusted_devices`

- Needs Doctrine ORM and DoctrineBundle, the mailer packages and `mailer.sender`. Entity `IQ2i\HerseBundle\Entity\TrustedDevice`, table `iq2i_herse_trusted_device`, default entity manager: generate a migration.
- `firewalls` is required. Cookie `herse_device_<firewall>` (override with `cookie_name`: letters, digits, `_`, `.`, `-`), `HttpOnly`, `Secure`, `SameSite=Lax`. `lifetime` is the inactivity after which a device is forgotten.
- `store_ip: false` keeps no IP (personal data). The raw User-Agent is never stored, only a label (`Firefox (macOS)`).
- `notify_first_device: false` registers the first device of a user silently (useful at roll-out).
- `notify_before_second_factor: true` warns as soon as the password is accepted from an unknown device (with `two_factor`).
- `revoke_on_password_change: true` revokes the devices of a user whose password changes, keeping the browser of the owner who changed it. Requires `password_change_notification`.
- `enabled` must be a literal boolean: the Doctrine mapping is added before environment variables are resolved.
- Purge: `bin/console iq2i:herse:purge-trusted-devices [--dry-run]`.

## `password_change_notification`

- Detected by Doctrine on the `password` field, sent after a successful flush: covers profile pages, reset links, administrators and console commands. No table.
- `notify_on_other_user: false` silences changes made by another authenticated user (administrator, impersonation). `notify_on_console: false` silences changes made outside a web request.
- Sends nothing on account creation, on the first password (empty previous value), on a removed password, on an automatic rehash during login, or while suspended (`PasswordChangeNotification::withoutNotification()`).
- Not detected: DQL `UPDATE`, raw SQL. A rolled back outer transaction does not cancel the email.

## `two_factor`

- Bridge to `scheb/2fa-bundle` (its bundle must be registered in `config/bundles.php`). Packages: `scheb/2fa-email` or `scheb/2fa-totp` according to `method`, `scheb/2fa-trusted-device` with `trusted_devices: true`, `symfony/rate-limiter` for the throttling.
- Defaults injected under `scheb_two_factor`, which the project configuration always overrides: email code of 6 digits sent by the bundle; TOTP issuer `mailer.app_name`; trusted device manager of the bundle.
- The email code goes to `getEmailAuthRecipient()` of the user class, not to the `email` property path.
- `trusted_devices: true` requires the `trusted_devices` feature, with the firewall watched there. The cookie and lifetime options of scheb then have no effect; `trusted_lifetime` counts from the verification and is not extended by logins.
- `throttling`: wrong second factors per user and firewall, whatever the IP. `throttling: false` removes it, only when the project brings its own. The login throttling cannot replace it.
- `enabled`, `method` and `trusted_devices` must be literal values, not environment variables.
- No backup codes.

## Validation errors of the configuration

The compilation fails with a message naming the fix:

- `iq2i_herse.users: class "…" does not exist.` / `the type "…" is used by several classes`.
- `iq2i_herse.users: "plain_password" requires iq2i_herse.password_policy to be enabled.`
- `iq2i_herse.two_factor.method is required: "email" or "totp".`
- `iq2i_herse.two_factor.trusted_devices requires iq2i_herse.trusted_devices to be enabled.`
- `iq2i_herse.two_factor requires … Try running "composer require …"`, and the same for each feature missing a package.
- `iq2i_herse.password_policy: max_length must be greater than or equal to min_length.`

## Services and public API

Public, safe to inject or call:

| Service / class | Use |
|---|---|
| `IQ2i\HerseBundle\TrustedDevice\TrustedDevices` | `list($user, ?$firewall)` (not expired, last used first), `isCurrent($device)`, `revoke($user, $deviceId)` (false when not owned), `revokeAll($user, keepCurrent: true)` (returns the count). The only way to revoke: it also clears the cookie. |
| `IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification` | `withoutNotification(callable)`, `disable()` / `enable()` (nestable), `isEnabled()`. A suspension ends with the request or message. |
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
| `TwoFactorSucceededEvent` | `reference` (null if the class is not declared), `user`, `firewall`, `ip` | Relays scheb. Never carries the code. |
| `TwoFactorFailedEvent` | same | Relays scheb. |
| `TwoFactorThrottledEvent` | same | Once, when the limit is reached. |

`TrustLevel` (`Known`, `Verified`) is the trust of a device: `Verified` means it passed a second factor and was trusted by the user.

## Console command

`iq2i:herse:purge-trusted-devices` deletes the expired trusted devices; `--dry-run` only counts them. Registered only with `trusted_devices` enabled. Schedule it (cron, Symfony Scheduler), for instance daily.

## Overridable templates and translations

- Templates in `templates/bundles/IQ2iHerseBundle/email/`: `layout.html.twig` (extended by the others), `new_device.html.twig`, `password_changed.html.twig`, `two_factor_code.html.twig`. Referenced as `@IQ2iHerse/email/…`.
- Translations: domain `iq2i_herse` (emails) and `validators` (password policy), English and French. Override a message by defining the same key in the project catalogue.
