# MokaCMS

The bundle has a bridge for MokaCMS (`moka/cms` 1.1). MokaCMS generates its user class when the cache is warmed up
and has no extension point for it, so the bridge does what a project would otherwise write by hand. It needs
Symfony 6.4, the only version supported by both, and PHP 8.2 or 8.3.

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    moka: true
    password_policy: true
    login_throttling: true
    trusted_devices: true
    password_change_notification: true
    two_factor: { method: email, trusted_devices: true }
```

## What the bridge does

- **Users**: declares `Moka\Cms\Entity\User\AbstractUser` (type `admin`, `email`, `locale`). The generated class
  does not exist when the container is compiled, so it cannot be declared itself.
- **Emails**: the sender and the application name default to `moka_cms.email_sender` and `moka_cms.site_name`.
- **Password policy**: applied to the reset screen of Moka, the only one that sets a password. The
  `moka:create-user` command is not covered.
- **Trusted devices**: registered for the `moka` firewall (`iq2i_herse.moka.firewall`), with a "My devices" screen
  in the menu of the administration.
- **Second factor by email**: the generated user class implements the interface of scheb and gets its two columns,
  the user screen gets a switch, and the code is asked on a screen with the layout of Moka. The authenticator app
  (`totp`) is not wired: it needs an enrolment screen.

## What stays in the project

Symfony does not let a bundle complete a firewall, and routes are imported by the project.

```yaml
# config/packages/security.yaml
security:
    firewalls:
        moka:
            login_throttling:
                limiter: iq2i_herse.login_throttling.limiter
            two_factor:
                auth_form_path: iq2i_herse_moka_two_factor
                check_path: iq2i_herse_moka_two_factor_check
                prepare_on_login: true

    access_control:
        # Before the rule of the administration.
        - { path: ^/admin/2fa, roles: IS_AUTHENTICATED_2FA_IN_PROGRESS }
        - { path: ^/admin, roles: ROLE_MOKA }
```

```yaml
# config/routes/iq2i_herse.yaml
herse_devices:
    resource: '@IQ2iHerseBundle/config/routes/moka_devices.yaml'
    prefix: /admin

herse_two_factor:
    resource: '@IQ2iHerseBundle/config/routes/moka_two_factor.yaml'
    prefix: /admin
```

The second factor adds two columns to the user table of Moka (`email_auth_enabled`, `auth_code`) and the trusted
devices a table: update the schema the way the project does.
