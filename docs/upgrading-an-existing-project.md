# Adding the bundle to an existing project

Each feature is independent and disabled by default, so a project adopts them one at a time. This guide gives an
order that keeps the risks low, and what to expect on a project that already has users.

## 1. Install and declare the users

```bash
composer require iq2i/herse-bundle
```

Declare the user classes of the project ([configuration](../README.md#configuration)). Nothing is registered in the
container yet: a disabled feature declares no service, listener or route. Enabling a feature without one of its
packages fails the container compilation with the `composer require` command to run.

## 2. Password policy

No schema, no email. Enable it and declare the plain password property of each user class
([guide](password-policy.md)).

- The existing passwords stay valid: the policy only checks a password when it is set, never when it is used to log in.
  Users meet it the next time they choose a password.
- The forms are covered through their `autocomplete="new-password"` fields: add the attribute where it is missing.
  DTOs and API payloads need the constraint added by hand or by a validation mapping file.
- The hash guard refuses weak passwords from every code path, fixtures and tests included: set
  `password_policy.hash_guard: false` under `when@test`, and wrap the imports of legacy accounts in
  `PasswordPolicyGuard::withoutGuard()`.
- Remove the checks written by hand: a `PasswordPolicy` constraint added to a form field that now gets it by itself
  reports each rule twice.

## 3. Login throttling

One line per firewall in `security.yaml` ([guide](login-throttling.md)). Before going to production:

- use a shared cache pool when the application runs on several servers, or the counters are per server;
- configure `trusted_proxies` behind a reverse proxy, or every client has the IP of the proxy and shares its limits;
- decide on the identifier limit (`identifier.enabled`), which lets a third party lock an account for a while.

If the project already sets `login_throttling` on a firewall, replace its limiter with the one of the bundle: only
one limiter applies.

## 4. Emails and password change notification

Configure `iq2i_herse.mailer` ([emails](emails.md)) and enable the notification
([guide](password-change-notification.md)). No migration is needed.

- Every password change is now reported to the owner, from any entry point, with no call to add in your code. Remove
  the email your code already sends for that, or users receive two.
- Imports, fixtures and data migrations that write passwords: wrap them in
  `PasswordChangeNotification::withoutNotification()`, or every account receives an email.
- The hashed password must be a Doctrine field. Declare its name in `users.<class>.password` when it is not
  `password`.

## 5. Trusted devices

Enable it on the firewalls to watch ([guide](trusted-devices.md)), then generate and run the migration of the
`iq2i_herse_trusted_device` table.

- **First login after the deployment**: no user has a device yet, so every user receives an email at their next login.
  Set `notify_first_device: false` for the roll-out, then switch it back if you want. The `NewDeviceEvent` is
  dispatched either way.
- A registry of devices of your own is not imported: the users start with no device. Remove it once the bundle is
  in production, and plug your screens on `TrustedDevices`.
- Schedule `bin/console iq2i:herse:purge-trusted-devices`, for instance daily.
- The cookie is `Secure`: a staging environment served over plain HTTP never keeps it, so each login looks like a new
  device.
- Token authenticated firewalls (API) are not covered.

## 6. Two-factor authentication

Last, because it changes the login for the users ([guide](two-factor.md)).

- The project keeps its `scheb_two_factor` configuration: the defaults of the bundle only fill what is not set.
- With `trusted_devices: true`, the trusted device cookie of scheb is replaced by the registry: the devices trusted
  with the cookie of scheb are no longer recognised, and the users give the second factor once more.
- Decide how a lost second factor is handled before deploying TOTP: the bundle provides no backup codes.
- The wrong codes are throttled by default, which requires `symfony/rate-limiter` and, on several servers, a shared
  cache pool (`two_factor.throttling.cache_pool`).

## Before going to production

- [ ] The migration of `iq2i_herse_trusted_device` is part of the deployment.
- [ ] `iq2i_herse.mailer.sender` belongs to a domain whose emails are delivered (SPF, DKIM).
- [ ] The login throttling and the second factor throttling use a shared cache, and the login throttling sees the
      right IP.
- [ ] The purge of the devices is scheduled.
- [ ] Imports and fixtures that set passwords are wrapped, and the email your code used to send is removed.
- [ ] The default templates are overridden in `templates/bundles/IQ2iHerseBundle/email/` if the emails must follow
      the graphic charter.
- [ ] For Sylius: the [Sylius guide](sylius.md) is applied, firewall by firewall.
