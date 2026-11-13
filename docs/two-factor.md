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
composer require bacon/bacon-qr-code                        # totp: the QR code of the enrollment screen
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
        required: true         # default: with totp, a user without authenticator app must enroll one
        trusted_devices: false # a trusted device skips the second factor, requires trusted_devices
        trusted_lifetime: '30 days' # how long a trusted device skips it, counted from its verification
        throttling:            # wrong second factors allowed to a user, enabled by default
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter # must be shared between servers
            lock_factory: auto             # a service id, or null to disable locking
```

`enabled`, `method` and `trusted_devices` must be literal values, not environment variables: the defaults of scheb
are prepared before the container resolves them.

## What the bundle does

- **Defaults for scheb**: injected according to the method, under your own `scheb_two_factor` configuration which
  always wins. `email`: the code is sent by the bundle and has 6 digits. `totp`: the issuer is `mailer.app_name`.
  Both: the code form is the one of the bundle. `trusted_devices`: the trusted device manager of scheb is the one of
  the bundle. No `scheb_2fa.yaml` is needed.
- **Routes, code form and entity traits**: see below.
- **Email method**: the code is sent with the email service of the bundle (template
  `@IQ2iHerse/email/two_factor_code.html.twig`, sender and translations of the [emails](emails.md)) to the address
  returned by `getEmailAuthRecipient()` of your user class, or, when it returns an empty string as the trait of the
  bundle does, to the `email` property path of `users`. A failure is logged and never breaks the login. Add
  `prepare_on_login: true` to the `two_factor` firewall to send the code as soon as the password is accepted.
- **Trusted devices**: with `trusted_devices: true`, ticking the "trust this device" box of the code form (the
  `_trusted` parameter of scheb) makes the device of the [registry](trusted-devices.md) *verified*. A verified device
  skips the second factor for `trusted_lifetime`, counted from the verification: unlike the expiration of the device,
  it is not extended by the logins, so a device in regular use is asked for the second factor again once the time is
  up, without being announced as a new one. Revoking it through `TrustedDevices`, or its expiration, brings the
  second factor back earlier. The cookie of scheb is not used any more, so its `trusted_device` options (`cookie_name`,
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

Three short steps. The routes, the code form, the enrollment screen and the entity methods are provided;
`security.yaml` stays yours, and the bundle checks it.

1. Declare the firewalls asking for the second factor, and import the routes of the bundle:

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    firewalls:
        main: ~
        admin:
            path_prefix: /admin              # must match the pattern of the firewall
            layout: admin/base.html.twig     # optional, see "The code form"
```

```yaml
# config/routes/iq2i_herse.yaml
iq2i_herse:
    resource: .
    type: iq2i_herse
```

Each firewall gets `iq2i_herse_<firewall>_two_factor_form` (`<path_prefix>/2fa`) and
`iq2i_herse_<firewall>_two_factor_check` (`<path_prefix>/2fa_check`).

2. Point the firewall to these routes in `security.yaml`:

```yaml
security:
    firewalls:
        admin:
            logout:
                path: /admin/logout
            two_factor:
                auth_form_path: iq2i_herse_admin_two_factor_form
                check_path: iq2i_herse_admin_two_factor_check
                enable_csrf: true
                prepare_on_login: true       # email method: send the code as soon as the password is accepted
    access_control:
        # Before the rules that match the same paths.
        - { path: ^/admin/2fa, roles: IS_AUTHENTICATED_2FA_IN_PROGRESS }
```

The container compilation fails, with these lines, while a declared firewall has no `two_factor`, uses other routes,
leaves the CSRF protection off, has no logout (scheb builds the "cancel" link of the form from it), does not cover
the path of the form, or has a broader access rule closing the form before the one above.
`iq2i_herse.firewalls.<name>.two_factor: false` leaves a firewall out.

3. Add the second factor to your user class, with the trait of the method, and generate the migration:

```php
use IQ2i\HerseBundle\TwoFactor\Model\EmailTwoFactorTrait;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    use EmailTwoFactorTrait;       // column "email_auth_code"
}
```

```php
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorInterface;
use IQ2i\HerseBundle\TwoFactor\Model\TotpTwoFactorTrait;

class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface
{
    use TotpTwoFactorTrait;        // column "totp_secret"
}
```

- With the email trait, every user of the class gets the second factor, and the code goes to the address declared
  in `iq2i_herse.users.<class>.email`. Override `isEmailAuthEnabled()` to leave some users out.
- With the TOTP trait, a user has the second factor once a secret is set (`setTotpSecret()`). The parameters are the
  ones every authenticator app understands (SHA-1, 30 seconds, 6 digits).
- An entity that already has the column under another name keeps it with an `#[ORM\AttributeOverride]`.

A project that wrote its own routes, template or entity methods keeps them: without `iq2i_herse.firewalls`, nothing
is checked, and its own `scheb_two_factor` configuration always wins.

## The code form

The form is `@IQ2iHerse/two_factor/form.html.twig`: a bare page, made to be dressed by your project.

- **Layout**: `iq2i_herse.ui.layout`, or `layout` of a firewall, is the template the page extends. It needs a `body`
  block (and may have a `title` one), as the `base.html.twig` of a Symfony project.

```yaml
iq2i_herse:
    ui:
        layout: base.html.twig
```

- **Markup**: override `templates/bundles/IQ2iHerseBundle/two_factor/_form.html.twig` to rewrite the form, or embed
  the original to change one block (`herse_two_factor_intro`, `herse_two_factor_error`, `herse_two_factor_code`,
  `herse_two_factor_trusted`, `herse_two_factor_actions`):

```twig
{# templates/bundles/IQ2iHerseBundle/two_factor/_form.html.twig #}
{% embed '@!IQ2iHerse/two_factor/_form.html.twig' %}
    {% block herse_two_factor_actions %}
        <button type="submit" class="btn btn-primary">{{ 'Verify'|trans({}, 'iq2i_herse') }}</button>
    {% endblock %}
{% endembed %}
```

- **Another template altogether**: `scheb_two_factor.email.template` or `scheb_two_factor.totp.template`, as with
  scheb alone. Keep the CSRF token field in it.

The texts are translated in English and French (`iq2i_herse` domain).

## Enrolling an authenticator app

With `method: totp`, each declared firewall gets the screen `iq2i_herse_<firewall>_two_factor_setup`
(`<path_prefix>/second-factor/setup`). It shows a QR code and the key to type by hand, and stores the secret on the
account once a first code proved the app has it. Until then the secret only lives in the session.

- **Mandatory by default**: scheb asks nothing of an account without a secret. With `required: true`, such an
  account is redirected to the screen from every page of the firewall until it enrolled; it can still log out.
  `required: false` leaves the enrollment to the user: link to the route from your account page.
- **Where it leads**: `iq2i_herse.firewalls.<name>.home`, a path or a route name, the path prefix by default.
- **QR code**: drawn on the server, as an inline SVG, with `bacon/bacon-qr-code` (which `endroid/qr-code` installs
  too). Without it, the screen only shows the key. Alias `IQ2i\HerseBundle\TwoFactor\QrCode\QrCodeRendererInterface`
  to your own service to draw it another way.
- **Wrong codes** count in the [throttling](#what-the-bundle-does) of the second factor.
- **Trusted devices**: with `trusted_devices: true`, the screen offers to trust the device used to enroll.
- **Impersonation**: somebody impersonating a user is neither redirected to the screen nor allowed on it.
- **Markup**: `two_factor/setup.html.twig` and `two_factor/_setup.html.twig` are dressed like the code form (blocks
  `herse_two_factor_setup_intro`, `_secret`, `_error`, `_code`, `_trusted`, `_actions`).

`IQ2i\HerseBundle\Event\TwoFactorEnabledEvent` is dispatched once the secret is stored, with the `UserReference`, the
user, the firewall and the IP, never the secret.

The secret is stored as scheb reads it, in clear text in the `totp_secret` column: protect the database and its
backups accordingly.

## Resetting a lost authenticator app

Backup codes are not provided: with TOTP, a user who lost their phone needs their second factor removed, and enrolls
a new app at the next login. The trusted devices of the user are revoked with it. Three ways, all with `method: totp`:

- **Console**, the last resort when nobody can do it from the application (the only administrator lost their phone):

```bash
bin/console iq2i:herse:reset-two-factor alice@example.com            # --type=admin when several user types share the address
```

- **Screen of the bundle**, for the users having a role you name. Without the role, no screen exists.

```yaml
iq2i_herse:
    two_factor:
        reset_role: ROLE_ADMIN
```

  Each declared firewall gets `iq2i_herse_<firewall>_two_factor_reset`
  (`<path_prefix>/second-factor/reset/{type}/{id}`, the type and the identifier of the `UserReference`): a
  confirmation page, then the reset on a POST protected against CSRF. Link to it from your user list, with an
  optional `back` parameter, a path of your site the screen returns to. It is closed to somebody impersonating a
  user.

- **Your own code**, through the public service `IQ2i\HerseBundle\TwoFactor\TwoFactorResetter`:
  `reset($user, by: $administrator)`.

With EasyAdmin, the action is ready: it links to the screen above, is only shown on the users having an
authenticator app, and comes back to the page it was clicked on.

```php
use IQ2i\HerseBundle\Bridge\EasyAdmin\ResetTwoFactorAction;

public function __construct(private readonly ResetTwoFactorAction $resetTwoFactor)
{
}

public function configureActions(Actions $actions): Actions
{
    return $actions->add(Crud::PAGE_INDEX, $this->resetTwoFactor->create());
}
```

`IQ2i\HerseBundle\Event\TwoFactorResetEvent` is dispatched after each reset, with the `UserReference`, the user and
who did it (`by`, null from the console).

## Checking the installation

Log in with an account having the second factor enabled: you are redirected to the code form, and (email method) the
code arrives. A wrong code dispatches `TwoFactorFailedEvent`, the right one `TwoFactorSucceededEvent`. Five
wrong codes in a row refuse the right one for 15 minutes and dispatch `TwoFactorThrottledEvent`. With
`trusted_devices: true`, tick the "trust this device" box, close the session and log in again: the code is not asked.
