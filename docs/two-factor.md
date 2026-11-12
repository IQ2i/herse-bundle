# Two-factor authentication

The bundle does not implement the second factor: it makes [scheb/2fa-bundle](https://github.com/scheb/2fa) easier to
enable and connects it to the other features. scheb stays configured under its own `scheb_two_factor` key, and is an
optional dependency: install it only on the projects that need it.

## Installation

The container compilation fails with the exact `composer require` command when the feature is enabled without scheb,
or when its bundle is not registered in `config/bundles.php`.

```bash
composer require scheb/2fa-bundle scheb/2fa-email           # method: email
composer require scheb/2fa-bundle scheb/2fa-totp            # method: totp
composer require scheb/2fa-trusted-device                   # trusted_devices: true
composer require symfony/rate-limiter                       # throttling, enabled by default
```

The email method also needs the packages of the [emails](emails.md) guide. A scheb installed but not enabled on a
firewall has no effect.

## Configuration

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    two_factor:
        enabled: true
        method: email          # or totp, required
        trusted_devices: false # a trusted device skips the second factor, requires trusted_devices
        throttling:            # wrong second factors allowed to a user, enabled by default
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter # must be shared between servers
            lock_factory: auto             # a service id, or null to disable locking
```

## What the bundle does

- **Defaults for scheb**: injected according to the method, under your own `scheb_two_factor` configuration which
  always wins. `email`: the code is sent by the bundle and has 6 digits. `totp`: the issuer is `mailer.app_name`.
  `trusted_devices`: the trusted device manager of scheb is the one of the bundle.
- **Email method**: the code is sent with the email service of the bundle (template
  `@IQ2iHerse/email/two_factor_code.html.twig`, sender and translations of the [emails](emails.md)) to the address
  returned by `getEmailAuthRecipient()` of your user class, not to the `email` property path of `users`. A failure is
  logged and never breaks the login. Add `prepare_on_login: true` to the `two_factor` firewall to send the code as
  soon as the password is accepted.
- **Trusted devices**: with `trusted_devices: true`, ticking the "trust this device" box of the code form (the
  `_trusted` parameter of scheb) makes the device of the [registry](trusted-devices.md) *verified*. A verified device
  skips the second factor until it expires or is revoked: revoking it through `TrustedDevices` brings the second
  factor back. The cookie of scheb is not used any more, so its `trusted_device` options (`cookie_name`,
  `lifetime`...) have no effect: the cookie and its lifetime come from `trusted_devices.firewalls`, and the firewall
  must be watched there. Trusting a device is up to the user, nothing is verified without it.
- **Registering the device**: while the second factor is pending, no device is registered and no email is sent: they
  follow the end of the login. With `trusted_devices.notify_before_second_factor: true`, the user is warned as soon as
  the password is accepted from an unknown device instead. It tells them their password is known to somebody even if
  the second factor then fails, and it warns again at each such attempt.

- **Throttling**: a user gets 5 wrong second factors per 15 minutes on a firewall, whatever the IP they come from;
  the next attempts are refused like a wrong code, even with the right one, until the window ends. The right code
  clears the counter, accepting the password again does not. The
  [login throttling](login-throttling.md) cannot play this role: its limits tied to the identifier are cleared each
  time the password is accepted, which somebody guessing the code already knows. `IQ2i\HerseBundle\Event\TwoFactorThrottledEvent`
  is dispatched once when the limit is reached. `throttling: false` removes the protection: only do it when the
  project brings its own.

`IQ2i\HerseBundle\Event\TwoFactorSucceededEvent` and `TwoFactorFailedEvent` relay the events of scheb, once for each
second factor passed or refused. They carry the `UserReference` (null when the class is not declared in `users`), the
user object, the firewall and the IP, never the code.

## What your project does

1. Add `two_factor` to the firewall, a logout path (scheb builds the logout URL of the form from it) and the access
   control rules, in `security.yaml`. The rule of the code form must come before the broader ones:

```yaml
security:
    firewalls:
        main:
            logout:
                path: /logout
            two_factor:
                auth_form_path: two_factor_login
                check_path: two_factor_check
                prepare_on_login: true
    access_control:
        - { path: ^/2fa, roles: IS_AUTHENTICATED_2FA_IN_PROGRESS }
```

2. Make your user class implement the interface of the method (`Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface`
   or `Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface`), with its fields, and generate the migration.
3. Declare the routes of the code form and of its check path (`two_factor_login` and `two_factor_check` above).
4. Provide the template of the code form (`scheb_two_factor.email.template` or `totp.template`).
5. Build the screen enabling the second factor. For TOTP, it generates a secret with
   `TotpAuthenticatorInterface::generateSecret()` and displays the QR code of `getQRContent()`.

The guide of scheb describes each step.

## Backup codes

Backup codes are not provided: with TOTP, a lost phone means an intervention of your support, to settle before
deploying it.

## Checking the installation

Log in with an account having the second factor enabled: you are redirected to the code form, and (email method) the
code arrives. A wrong code dispatches `TwoFactorFailedEvent`, the right one `TwoFactorSucceededEvent`. With
`trusted_devices: true`, tick the "trust this device" box, close the session and log in again: the code is not asked.
