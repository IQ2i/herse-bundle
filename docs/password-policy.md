# Password policy

One validation constraint carries the whole policy. It is applied automatically to the plain password
property of the user classes you declare, and it can be used by hand on DTOs, API payloads and commands.

## Installation

The feature only needs the required dependencies of the bundle (`symfony/validator`). Two packages are optional:

```bash
composer require symfony/http-client   # only for check_compromised
composer require symfony/form          # only for PasswordPolicyType
```

## Configuration

Declare, for each user class, the property holding the plain password, then enable the feature:

```yaml
# config/packages/iq2i_herse.yaml
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

A special character is anything that is neither a letter nor a digit, so accented letters count as letters. Each
broken rule has its own message, translated in English and French (`validators` domain). An invalid encoding gives
a dedicated error.

## Outside of the user classes

DTOs, API payloads and commands use the constraint without argument, it reads the configuration of the bundle:

```php
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;

#[PasswordPolicy]
public ?string $newPassword = null;
```

With `symfony/form`, `IQ2i\HerseBundle\Form\Type\PasswordPolicyType` is a repeated password field that applies the
constraint.

When the models are not yours (the models of a library, such as the ones of Sylius), use a validation mapping file,
see [Sylius](sylius.md#password-policy).

## Checking the installation

Validate a plain password through the validator service, with the groups you declared. It must return one violation
for each rule the value breaks:

```php
$user = new App\Entity\User();
$user->setPlainPassword('weak');

$violations = $validator->validate($user);
```
