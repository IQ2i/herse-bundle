# Trusted devices

The bundle keeps a registry of the browsers of each user. After a successful interactive login on a watched firewall,
the browser is recognised by a long-lived cookie. When it is unknown, it is registered, the cookie is set and the
user receives an email.

## Installation

```bash
composer require doctrine/orm doctrine/doctrine-bundle symfony/mailer symfony/twig-bundle symfony/translation
```

The mailer is configured as described in the [emails](emails.md) guide.

## Configuration

```yaml
# config/packages/iq2i_herse.yaml
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
        notify_before_second_factor: false # with two_factor, warn when the password is accepted
        revoke_on_password_change: false # requires password_change_notification
```

## Database

The entity is provided and mapped by the bundle (table `iq2i_herse_trusted_device`): generate a migration with the
tool of your project after enabling the feature.

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

The entity manager used is the default one.

## What is stored

A device is bound to a user reference (type and identifier), never to a Doctrine relation, and to a firewall: the
trust given on a shop does not apply to an administration. Only the SHA-256 of the cookie token is stored, so a leak
of the table cannot be used to forge a cookie. The IP address and the label (e.g. `Firefox (macOS)`, derived from the
User-Agent) are for display only: they never recognise a device, and the raw User-Agent is not kept.

## Behaviour

- **Known device**: the last use date, the IP and the expiration are updated, and the cookie is renewed. Nothing is
  sent.
- **Unknown or expired device**: it is registered with a new token, and the user is warned. A token chosen by the
  client is never adopted. A browser shared by two users trusts the last one to log in.
- **Verified device**: a device that passed a second factor and was trusted by the user. It skips the second factor on
  the next logins, see [two-factor authentication](two-factor.md).
- **Remember-me**: refreshes an existing device, never registers one. Only interactive authenticators count: token
  authenticators (API) are ignored, as is a user of a class not declared in `users`.
- **First login after the installation**: every user starts without any device. Set `notify_first_device: false` to
  register their first device without an email (the event below is still dispatched).
- **Retention**: the expired devices of a user are deleted when they log in. Schedule the command that deletes the
  others, e.g. daily:

```bash
bin/console iq2i:herse:purge-trusted-devices            # --dry-run counts without deleting
```

The cookie is `HttpOnly`, `Secure` and `SameSite=Lax`, so a project served over plain HTTP (localhost excepted) never
keeps it. Revoking a device removes the trust, it does not end the session in progress. The firewalls that
authenticate with a token (API) are not covered: the registry relies on a browser cookie.

## Listing and revoking the devices

Projects list and revoke the devices through the public `IQ2i\HerseBundle\TrustedDevice\TrustedDevices` service, and
build their own screen. The repository stays internal.

```php
$devices = $trustedDevices->list($user);              // not expired, last used first; optional firewall
$trustedDevices->isCurrent($device);                  // is it the browser of this request?
$trustedDevices->revoke($user, $deviceId);            // false when the user has no such device
$trustedDevices->revokeAll($user, keepCurrent: true); // returns the number of devices revoked
```

Every revocation goes through this service, which also clears the cookie when the current browser is revoked. With
`revoke_on_password_change`, the devices of a user are revoked when their password changes (see the
[notification](password-change-notification.md): the same cases, the same suspension). The owner who changes their
password keeps the browser they are using. This happens whatever the settings of the notification are.

## Event

`IQ2i\HerseBundle\Event\NewDeviceEvent` is dispatched once a new device is registered, with the `UserReference`, the
user object, the firewall, the label, the IP, the date and the email (null when none is meant to be sent). A listener
can call `cancel()`, or `setEmail()` to replace or supply the message.

## Checking the installation

Log in from a browser: the response sets the cookie of the firewall (`herse_device_<firewall>` by default), a row
appears in `iq2i_herse_trusted_device` and the email arrives. Log in again with the same cookie: nothing is sent.
