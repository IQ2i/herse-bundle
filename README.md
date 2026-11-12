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
        App\Entity\ShopUser:
            type: shop
            email: customer.email
    password_policy: true        # or { enabled: true }
```

## Development

```bash
make install
make ci
```

## License

MIT
