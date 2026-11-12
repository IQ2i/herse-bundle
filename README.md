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

## Development

```bash
make install
make ci
```

## License

MIT
