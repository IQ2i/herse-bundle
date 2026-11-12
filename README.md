# Herse

Account security building blocks for Symfony and Sylius. Each feature is configured on its own and is
disabled by default; a disabled feature registers no service, listener or route.

| Feature                        | Config key                     |
| ------------------------------ | ------------------------------ |
| Password policy                | `password_policy`              |
| Login throttling               | `login_throttling`             |
| Trusted devices                | `trusted_devices`              |
| Password change notification   | `password_change_notification` |
| Two-factor authentication      | `two_factor`                   |

Requirements: PHP 8.2+, Symfony 6.4, 7.x or 8.x.

## Installation

```bash
composer require iq2i/herse-bundle
```

## Configuration

Declare the user classes of your project. A user is referred to by a neutral reference (a type and an
identifier), never by a Doctrine relation.

```yaml
iq2i_herse:
    users:
        App\Entity\AdminUser:
            type: admin          # unique across the declared classes
            email: email         # property path of the recipient address
            id: id               # property path of the identifier, "id" by default
            password: password   # Doctrine field of the hashed password, "password" by default
            locale: locale       # property path of the user locale, optional
        App\Entity\ShopUser:
            type: shop
            email: customer.email
    password_policy: true        # or { enabled: true }
```

## Password policy

Enable the feature and declare, for each user class, the property holding the plain password. The
policy is then applied to it by the validator, with no annotation on your classes.

```yaml
iq2i_herse:
    users:
        App\Entity\User:
            type: shop
            email: email
            plain_password: plainPassword
        App\Entity\AdminUser:
            type: admin
            email: email
            plain_password: { property: plainPassword, groups: [admin] }   # validation groups, "Default" otherwise
    password_policy:
        enabled: true
        min_length: 12                 # default
        max_length: 4096               # default, so that oversized inputs are never hashed
        required_character_types: 4    # among lowercase, uppercase, digit, special; 0 disables the rule
        check_compromised: false       # needs symfony/http-client
```

A special character is anything that is neither a letter nor a digit, so accented letters count as
letters. Each broken rule has its own message, translated in English and French (`validators` domain).

Outside of mapped properties (DTOs, API payloads, commands), use the constraint without argument:

```php
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;

#[PasswordPolicy]
public ?string $newPassword = null;
```

With `symfony/form`, `IQ2i\HerseBundle\Form\Type\PasswordPolicyType` is a repeated password field that
applies the constraint.

## Login throttling

Requires `symfony/rate-limiter`. The bundle provides a limiter combining up to three limits, each scoped
to the firewall: failed attempts per identifier + IP, per IP and, optionally, per identifier alone.

```yaml
iq2i_herse:
    login_throttling:
        enabled: true
        identifier_and_ip: { limit: 5, interval: '1 minute' }     # defaults
        ip: { limit: 25, interval: '1 minute' }                   # defaults
        identifier: { enabled: false, limit: 20, interval: '15 minutes' }   # disabled by default
        cache_pool: cache.rate_limiter     # default
        lock_factory: auto                 # lock.factory when it exists, null to disable
```

Then add one line per firewall in `security.yaml`, as the bundle cannot complete the firewall
configuration itself:

```yaml
security:
    firewalls:
        admin:
            login_throttling:
                limiter: iq2i_herse.login_throttling.limiter
        shop:
            login_throttling:
                limiter: iq2i_herse.login_throttling.limiter
```

- The identifier limit covers an attack distributed over many IPs on one account, but it lets a third
  party temporarily lock someone else's account: enable it knowingly.
- The identifier is trimmed and lowercased before it is used, and neither the identifier nor the IP is
  readable in the cache keys.
- A successful login clears the identifier limits, never the IP one. It relies on the native listener,
  which does not do it on older Symfony versions.
- The counters live in the application cache: on a multi-server project, use a shared cache pool.
- Behind a reverse proxy, the IP is only right when the `trusted_proxies` framework option is configured.

`IQ2i\HerseBundle\Event\LoginThrottledEvent` is dispatched when a failed attempt exhausts a limit (once per
window and limit), with the limit (`LoginThrottlingLimit`), the normalized identifier, the IP and the firewall.

## Trusted devices

Requires `doctrine/orm`, `doctrine/doctrine-bundle` and the packages of the [emails](#emails). The bundle
keeps a registry of the browsers of each user. After a successful interactive login on a watched firewall,
the browser is recognised by a long-lived cookie. When it is unknown, it is registered, the cookie is set
and the user receives an email.

```yaml
iq2i_herse:
    mailer:
        sender: no-reply@example.com
    trusted_devices:
        enabled: true
        firewalls:                       # required: the firewalls whose logins are watched
            main: ~                      # the cookie is "herse_device_main", and lasts 30 days
            admin:
                cookie_name: admin_device
                lifetime: 7 days         # inactivity after which the device is forgotten
        store_ip: true                   # default: keep the last IP of the device, for display
        notify_first_device: true        # default: also warn for the first device of a user
        revoke_on_password_change: false # requires password_change_notification
```

The entity is provided and mapped by the bundle (table `iq2i_herse_trusted_device`): generate a migration
with the tool of your project after enabling the feature. A device is bound to a user reference (type and
identifier), never to a Doctrine relation, and to a firewall: the trust given on a shop does not apply to
an administration. Only the SHA-256 of the cookie token is stored, so a leak of the table cannot be used to
forge a cookie. The IP address and the label (e.g. `Firefox (macOS)`, derived from the User-Agent) are for
display only: they never recognise a device.

- **Known device**: the last use date, the IP and the expiration are updated, and the cookie is renewed.
  Nothing is sent.
- **Unknown or expired device**: it is registered with a new token, and the user is warned. A token chosen
  by the client is never adopted. A browser shared by two users trusts the last one to log in.
- **Remember-me**: refreshes an existing device, never registers one. Only interactive authenticators
  count: token authenticators (API) are ignored, as is a user of a class not declared in `users`.
- **First login after the installation**: every user starts without any device. Set
  `notify_first_device: false` to register their first device without an email (the event below is still
  dispatched).
- **Retention**: the expired devices of a user are deleted when they log in. Schedule the command that
  deletes the others, e.g. daily:

```bash
bin/console iq2i:herse:purge-trusted-devices            # --dry-run counts without deleting
```

The cookie is `HttpOnly`, `Secure` and `SameSite=Lax`, so a project served over plain HTTP (localhost
excepted) never keeps it. Revoking a device removes the trust, it does not end the session in progress. The firewalls that
authenticate with a token (API) are not covered: the registry relies on a browser cookie.

Projects list and revoke the devices through the public `IQ2i\HerseBundle\TrustedDevice\TrustedDevices`
service, and build their own screen. The repository stays internal.

```php
$devices = $trustedDevices->list($user);              // not expired, last used first; optional firewall
$trustedDevices->isCurrent($device);                  // is it the browser of this request?
$trustedDevices->revoke($user, $deviceId);            // false when the user has no such device
$trustedDevices->revokeAll($user, keepCurrent: true); // returns the number of devices revoked
```

Every revocation goes through this service, which also clears the cookie when the current browser is
revoked. With `revoke_on_password_change`, the devices of a user are revoked when their password changes
(see the [notification](#password-change-notification): the same cases, the same suspension). The owner
who changes their password keeps the browser they are using. This happens whatever the settings of the
notification are.

`IQ2i\HerseBundle\Event\NewDeviceEvent` is dispatched once a new device is registered, with the
`UserReference`, the user object, the firewall, the label, the IP, the date and the email (null when none is
meant to be sent). A listener can call `cancel()`, `setEmail()` to replace or supply the message.
The entity manager used is the default one.

## Password change notification

Requires `doctrine/orm`, `doctrine/doctrine-bundle` and the packages of the [emails](#emails). The owner
of an account receives an email when the hashed password of a declared user class changes. The change is
detected by Doctrine, so profile pages, reset links, administrators and console commands are all covered
without any call in your code. The email is sent once the flush has succeeded, never before.

```yaml
iq2i_herse:
    mailer:
        sender: no-reply@example.com
    password_change_notification:
        enabled: true
        notify_on_other_user: true   # default: another authenticated user (an administrator) changed it
        notify_on_console: true      # default: changed outside of a web request (console command, worker)
```

The email gives the date and time of the change and tells the user what to do if they did not make it.
It contains no reset link, on purpose.

No email is sent for:

| Case                                      | Rule                                                                                       |
| ----------------------------------------- | ------------------------------------------------------------------------------------------ |
| Account creation                          | Nothing is sent on insertion.                                                              |
| First password of an account              | Nothing is sent when the previous value is empty.                                          |
| Password removed                          | Nothing is sent when the new value is empty.                                               |
| Automatic rehash at login                 | Changes made between the credentials check and the end of the login are not user changes.  |
| Fixtures and imports                      | Switch the notification off around the processing, see below.                              |

A password reset followed by an automatic login is still notified: the password is written before the
authentication starts.

```php
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;

$notification->withoutNotification(function () use ($importer): void {
    $importer->import();
});
// or $notification->disable() then $notification->enable(), which can be nested
```

`IQ2i\HerseBundle\Event\PasswordChangedEvent` is dispatched before the email is sent. It carries the
`UserReference`, the user object, the date of the change, the context and the email itself. A listener can
call `cancel()` to prevent the sending, or `setEmail()` to replace the message. The context
(`PasswordChangeContext`) is `Self` for a web request made by the owner or by somebody with no
authenticated user (a reset link), `OtherUser` for a web request made by another authenticated user, and
`Console` when there is no web request.

The hashed password must be a field mapped by Doctrine, otherwise a `LogicException` is thrown on the
first update of such a user.

## Emails

Every email of the bundle goes through one internal sender based on Symfony Mailer, and requires
`symfony/mailer`, `symfony/twig-bundle` and `symfony/translation` as soon as a feature sending emails is
enabled (the container compilation fails with the exact `composer require` command otherwise). Mailer
must be configured in the framework (`framework.mailer`).

```yaml
iq2i_herse:
    mailer:
        sender: no-reply@example.com   # required
        sender_name: Example           # optional
        app_name: Example shop         # optional, available as "app_name" in the templates
```

- **Templates**: one Twig template per email, extending `@IQ2iHerse/email/layout.html.twig`. Override a
  template, or only the layout to apply your own design to every email, in
  `templates/bundles/IQ2iHerseBundle/email/`. The default rendering is deliberately neutral.
- **Translations**: English and French, in the `iq2i_herse` domain. The email uses the locale returned by
  the `locale` property path of the user class when it is declared, the default locale otherwise.
- **Recipient**: read from the `email` property path of the user class. When there is none, or when it is
  not a valid address, nothing is sent and a warning is logged.
- **Asynchronous sending**: nothing to configure. When Messenger routes the emails to a transport in your
  project, the emails of the bundle follow.
- **Failures**: an error while sending never fails a login or a password change, it is logged.

## Development

```bash
make install
make ci
```

## License

MIT
