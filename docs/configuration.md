# Configuration reference

Every option of `iq2i_herse`, with its default value. The guide of each feature explains what the options do and
what the feature asks of your project.

Every feature accepts the shortcut `feature: true` (same as `{ enabled: true }`) and `feature: false`. A feature
left out is disabled.

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

    moka:
        enabled: false
        firewall: moka                      # firewall of the MokaCMS administration

    two_factor:
        enabled: false
        method: ~                           # email | totp, required when enabled
        required: true                      # totp: a user without authenticator app is sent to the enrollment screen
        reset_role: ~                       # totp: the role allowed to reset the second factor of a user on the screen of the bundle
        trusted_devices: false
        trusted_lifetime: '30 days'
        code_reuse_cache: cache.app         # pool remembering the accepted codes, refused a second time; null to disable
        throttling:
            enabled: true
            limit: 5
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
            lock_factory: auto
```

Intervals are relative date formats (`'1 minute'`, `'15 minutes'`, `'7 days'`): an invalid one fails the container
compilation, as does an option of a feature whose package is missing.

`bin/console config:dump-reference iq2i_herse` prints the same tree from the installed version.

## Twig functions

| Function | Returns |
|---|---|
| `herse_password_policy()` | The rules of the [password policy](password-policy.md), translated, to list next to a password field. |
| `herse_two_factor_reset_path(user, back = null)` | The path of the screen [resetting the second factor](two-factor.md#resetting-a-lost-authenticator-app) of a user, or null when the current user may not do it. |
| `herse_layout(kind = 'account')` | The templates a screen of the bundle extends. `kind` is `security` for a step of the login, `account` for a page of a logged in user. |
| `herse_base_layout()` | The layout of your project the screens are placed in: `firewalls.<name>.layout`, `ui.layout` otherwise. |
| `herse_template(name)` | The template to include for a part of a screen, e.g. `two_factor/_form.html.twig`: the one of the theme of the current firewall when it has it, the one of the bundle otherwise. |

The last three are for the screens you write in the style of the ones of the bundle, so that they follow the layout
and the theme of the firewall they are shown on:

```twig
{% extends herse_layout('account') %}

{% block herse_title %}My security settings{% endblock %}

{% block herse_content %}
    <p>...</p>
{% endblock %}
```

A theme is a directory of your templates (`firewalls.<name>.theme`). A template of the bundle found in it under the
same name replaces the one of the bundle on that firewall, and `security.html.twig` or `account.html.twig` replaces
the frame the screens of that kind extend. Whatever is missing from it falls back on the bundle.
