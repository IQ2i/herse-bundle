# Herse

Account security building blocks for Symfony and Sylius. Each feature is configured on its own and is
disabled by default; a disabled feature registers no service, listener or route.

| Feature                        | Config key                     | Guide                                                              |
| ------------------------------ | ------------------------------ | ------------------------------------------------------------------ |
| Password policy                | `password_policy`              | [docs/password-policy.md](docs/password-policy.md)                 |
| Login throttling               | `login_throttling`             | [docs/login-throttling.md](docs/login-throttling.md)               |
| Trusted devices                | `trusted_devices`              | [docs/trusted-devices.md](docs/trusted-devices.md)                 |
| Password change notification   | `password_change_notification` | [docs/password-change-notification.md](docs/password-change-notification.md) |
| Two-factor authentication      | `two_factor`                   | [docs/two-factor.md](docs/two-factor.md)                           |

The three security emails (new device, password change, two-factor code) share one sender, described in
[docs/emails.md](docs/emails.md).

Requirements: PHP 8.2+, Symfony 6.4, 7.x or 8.x. Sylius 2.2 and 2.3 are supported, see
[docs/sylius.md](docs/sylius.md).

## Installation

```bash
composer require iq2i/herse-bundle
```

Register the bundle in `config/bundles.php` if Symfony Flex did not:

```php
IQ2i\HerseBundle\IQ2iHerseBundle::class => ['all' => true],
```

Each feature then lists the packages it needs: enabling a feature without one of them fails the container
compilation with the exact `composer require` command to run. On a project that already has users, follow
[docs/upgrading-an-existing-project.md](docs/upgrading-an-existing-project.md).

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
    firewalls:                   # the firewalls the features protect
        admin: ~
        main: ~
    password_policy: true        # or { enabled: true }
```

The bundle never writes your security configuration. It checks it instead: when a declared firewall misses a line
a feature relies on, the container compilation fails with the lines to add to `security.yaml`.

The other options are described in the guide of each feature.

## AI agents

The bundle ships a skill and instructions for [Symfony Mate](https://symfony.com/doc/current/ai/components/mate.html),
so that an AI agent can install and configure the features:

```bash
composer require --dev symfony/ai-mate
vendor/bin/mate discover
```

## Development

The tools run in Docker.

```bash
make install
make ci
```

The Sylius application of the test suite has its own dependencies:

```bash
make sylius-install      # SYLIUS_VERSION=2.2 for the other supported version
make sylius-test
```

## License

MIT
