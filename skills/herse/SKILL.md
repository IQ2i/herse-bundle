---
name: herse
description: Install, configure and use Herse (iq2i/herse-bundle), the account security bundle for Symfony and Sylius. Use it whenever the project has an `iq2i_herse` configuration or the iq2i/herse-bundle package, or when the user talks about Herse, a password policy, login throttling or brute-force protection, trusted devices or "new device" emails, a "your password was changed" notification, or two-factor authentication (scheb/2fa, TOTP, email code) on a Symfony or Sylius application, even if Herse is not named.
---

# Herse

Herse gives a Symfony or Sylius application five independent account security features, each one **disabled by default** (a disabled feature registers no service, listener or route):

| Feature | Config key | Guide |
|---|---|---|
| Password policy | `password_policy` | `docs/password-policy.md` |
| Login throttling | `login_throttling` | `docs/login-throttling.md` |
| Trusted devices | `trusted_devices` | `docs/trusted-devices.md` |
| Password change notification | `password_change_notification` | `docs/password-change-notification.md` |
| Two-factor authentication | `two_factor` | `docs/two-factor.md` |

The complete list of options with their defaults, the public services, the events and the validation errors is in [references/configuration.md](references/configuration.md): read it before writing anything beyond a minimal configuration.

The guides ship with the package, in `vendor/iq2i/herse-bundle/docs/`. This skill gives the method and the pitfalls; **read the guide of a feature before configuring it**, they hold every option and behaviour. Also available: `docs/emails.md` (the shared sender), `docs/sylius.md` and `docs/upgrading-an-existing-project.md`.

## 1. Check the installation first

Verify instead of assuming:

- `composer show iq2i/herse-bundle`, and `IQ2i\HerseBundle\IQ2iHerseBundle::class => ['all' => true]` in `config/bundles.php`.
- `config/packages/iq2i_herse.yaml`, and the resolved values with `bin/console debug:config iq2i_herse`. The full list of options and defaults: `bin/console config:dump-reference iq2i_herse`.
- Which features are enabled, which firewalls exist (`config/packages/security.yaml`), and whether the project is a Sylius one (`sylius/sylius` in `composer.json`).

Run `bin/console` and `composer` where the project runs. In Docker, use the container, the `Makefile` or the scripts of the project, not the host.

## 2. Install and declare the users

```bash
composer require iq2i/herse-bundle
```

Every feature needs the user classes of the project. A user is referred to by a neutral reference (a type and an identifier), never by a Doctrine relation:

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    users:
        App\Entity\AdminUser:
            type: admin          # unique across the declared classes
            email: email         # property path of the recipient address (customer.email for a Sylius shop user)
            id: id               # default
            password: password   # Doctrine field of the hashed password, default
            locale: locale       # optional, translates the emails
            plain_password: plainPassword   # optional, or { property: plainPassword, groups: [admin] }
```

Look at the user entities (`getEmail()`, the plain password property, the Doctrine mapping of the hashed password) before writing the declaration, and copy the names from the code.

Declare the firewalls to protect as well (`iq2i_herse.firewalls: { main: ~ }`, the names of `security.yaml`). The bundle never writes `security.yaml`, it checks it: the compilation fails with the exact lines to add when a declared firewall misses something an enabled feature relies on.

## 3. Enable a feature

For each feature, in this order:

1. Read its guide.
2. Install its packages. Enabling a feature without one of them fails the container compilation **with the exact `composer require` command to run**: run `bin/console cache:clear` and use that command rather than guessing the packages.
3. Add its configuration, then the part that belongs to the project (see below): the bundle never writes `security.yaml` nor the user entities.
4. Run the check described at the end of its guide.

What each feature asks of the project:

- **`password_policy`**: `plain_password` on the user classes when they have one. Forms are covered through their `autocomplete="new-password"` fields (add the attribute, do not add the constraint by hand), console questions through `PasswordQuestionValidator`, any other code through `PasswordPolicyChecker`; DTOs use `#[PasswordPolicy]`. Models that are not yours (Sylius ones) need a validation mapping file, see `docs/sylius.md`. The hash guard refuses a weak password from every code path: set `hash_guard: false` under `when@test` (and where weak fixtures are loaded) instead of weakening the policy. `check_compromised` requires `symfony/http-client`.
- **`login_throttling`**: one `login_throttling: { limiter: iq2i_herse.login_throttling.limiter }` line **per firewall** in `security.yaml`. Without it, nothing is throttled: declare the firewalls in `iq2i_herse.firewalls`, and a missing line fails the compilation with the lines to add.
- **`trusted_devices`**: watches the firewalls of `iq2i_herse.firewalls` (or its own `firewalls`), `mailer.sender` is required. It adds the table `iq2i_herse_trusted_device`: generate and run the migration with the tool of the project (`doctrine:migrations:diff`). The "my devices" screen is provided on the declared firewalls (`iq2i_herse_<firewall>_devices`): import the routes and link to it from the account page, do not write a controller. Schedule `bin/console iq2i:herse:purge-trusted-devices` (daily, `--dry-run` counts only).
- **`password_change_notification`**: needs Doctrine, the mailer and `mailer.sender`. No table. It covers every entry point, so remove a "password changed" email the project already sends, and wrap imports and fixtures in `PasswordChangeNotification::withoutNotification()`.
- **`two_factor`**: `method` (`email` or `totp`) is required. Declare the firewalls (`iq2i_herse.firewalls`, with `path_prefix` for a firewall under a prefix), import the routes (`{ resource: ., type: iq2i_herse }`), add the trait of the method to the user entity (`EmailTwoFactorTrait` or `TotpTwoFactorTrait`, then a migration), and write the `two_factor` block of the firewall and the `IS_AUTHENTICATED_2FA_IN_PROGRESS` access rule in `security.yaml`: the compilation error gives the exact lines. Do not write routes, a `scheb_2fa.yaml`, a code form template, an enrollment controller or a listener forcing the enrollment: they are provided, set `ui.layout` to the base template of the project and `firewalls.<name>.home` to the page the enrollment leads to. With TOTP, install `bacon/bacon-qr-code` for the QR code, and set `two_factor.reset_role` so that administrators can reset a lost authenticator app from the screen of the bundle (`iq2i:herse:reset-two-factor <email>` from the console; `ResetTwoFactorAction` with EasyAdmin). Backup codes are not provided.
- **Emails** (trusted devices, password change, email code): `iq2i_herse.mailer.sender` is required, and `framework.mailer` must be configured. Templates are overridden in `templates/bundles/IQ2iHerseBundle/email/`.

`enabled`, `two_factor.method` and `two_factor.trusted_devices` must be **literal values**, not environment variables: they are read before the container resolves them.

## 4. Sylius

The bundle contains no Sylius code: it works through the per-class and per-firewall configuration. Follow `docs/sylius.md`: two user types (`admin`, `shop`), `customer.email` for the shop user, the validation groups of Sylius for the password policy, one line per firewall (`admin`, `shop`), and the form models of Sylius for the change and the reset of a password. The `api_*` firewalls authenticate with a token and are not covered by the trusted devices.

## 5. Existing project

On a project that already has users, enable the features one at a time in the order of `docs/upgrading-an-existing-project.md`: users, password policy, login throttling, emails and password change notification, trusted devices, then two-factor. Mind the effects on real users: the first login after enabling the trusted devices emails everyone (`notify_first_device: false` for the roll-out), and the notification emails every password change.

## 6. Tests

- Cover the project code you add (the user entity methods, the listeners on the Herse events, the screens) with tests; the bundle itself is tested.
- Passing the second factor in a functional test: `IQ2i\HerseBundle\Test\TwoFactorTestTrait` (`passSecondFactor($client, $this->totpCode($secret))`, or the `getEmailAuthCode()` of the user for the email method).
- Keep the features **enabled in the test environment** when the tests depend on them, instead of disabling them. A login test that fails on the throttling or on the second factor means the test must adapt (reset the cache pool, log in with the second factor), not the protection.
- Events to hook into: `LoginThrottledEvent`, `NewDeviceEvent`, `PasswordChangedEvent`, `TwoFactorSucceededEvent`, `TwoFactorFailedEvent`, `TwoFactorThrottledEvent`, `TwoFactorEnabledEvent`, `TwoFactorResetEvent`, `TrustedDevicesRevokedEvent` (namespace `IQ2i\HerseBundle\Event`). A listener can `cancel()` or `setEmail()` on the ones that send an email.
- Trusted devices are listed and revoked on the screen of the bundle, or in code through the public `IQ2i\HerseBundle\TrustedDevice\TrustedDevices` service.

## 7. Checklist

1. `bin/console debug:config iq2i_herse` shows the expected values and the container compiles.
2. Each enabled feature has its packages, its project-side wiring (`security.yaml`, entity, routes) and its migration.
3. The check at the end of the guide of each feature passes.
4. The migration of `iq2i_herse_trusted_device` is part of the deployment and the purge is scheduled.
5. `mailer.sender` belongs to a domain whose emails are delivered (SPF, DKIM).
6. On several servers, the rate limiter cache pools are shared, and behind a reverse proxy `trusted_proxies` is set, otherwise every client shares the IP of the proxy.
7. The tests, static analysis and coding standard of the project pass.

## Limits

- The screens of the bundle are bare pages: give them the layout of the project (`ui.layout`, `firewalls.<name>.layout`) and, to restyle the forms, a theme (`firewalls.<name>.theme`). There is no ready-made theme for the administration of Sylius.

- The cookie of the trusted devices is `Secure`: over plain HTTP (localhost excepted) it is never kept and every login looks like a new device.
- Token authenticated firewalls (API) are not covered by the trusted devices.
- The password change is detected through the Doctrine entity manager: a DQL `UPDATE` or a raw SQL query sends nothing.
- Secrets, passwords and one-time codes never appear in logs or events: do not add them when writing a listener.
