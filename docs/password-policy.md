# Password policy

One validation constraint carries the whole policy. It is applied automatically to the plain password property of
the user classes you declare and to the forms where a password is chosen. Behind them, the password hasher refuses
a password that breaks the policy, so a code path that validates nothing cannot store a weak one.

## Installation

The feature only needs the required dependencies of the bundle (`symfony/validator`). Two packages are optional:

```bash
composer require symfony/http-client   # only for check_compromised
composer require symfony/form          # only for the form fields
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
        hash_guard: true               # default: refuse to hash a password that breaks the policy
        forms:
            auto: true                 # default: check every field with autocomplete="new-password"
            help: true                 # default: describe the policy in the help of these fields
```

A special character is anything that is neither a letter nor a digit, so accented letters count as letters. Each
broken rule has its own message, translated in English and French (`validators` domain). An invalid encoding gives
a dedicated error.

## The hash guard

The password hasher of Symfony (`UserPasswordHasherInterface`) is decorated: hashing a password that breaks the
policy throws a `IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyViolationException`, whatever calls it (a controller,
a console command, an API, the password updater of Sylius). Its `getMessages()` are the translated messages of the
broken rules, and it never carries the password.

It is a net, not a user interface: validate first (see below) so that the person gets a form error or a console
message rather than an exception. Some points to know:

- Only the local rules are checked: the data breach lookup of `check_compromised` is a network call, it stays in
  the validation.
- The rehash of an existing password at login does not go through it: existing passwords keep working.
- Fixtures, tests and imports of legacy accounts often hash weak passwords on purpose. Disable the guard for an
  environment, or suspend it around the code:

```yaml
# config/packages/iq2i_herse.yaml
when@test:
    iq2i_herse:
        password_policy:
            hash_guard: false          # or '%env(bool:HERSE_HASH_GUARD)%'
```

```php
use IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyGuard;

$guard->withoutGuard(fn () => $this->importLegacyAccounts());
```

## Forms

With `symfony/form`, every `PasswordType` field with `autocomplete="new-password"` is checked, mapped to an object
or not (`mapped: false`), whatever the validation groups of the form. In a `RepeatedType`, only the first field is.
The login form, whose field is `current-password`, is never touched.

```php
$builder->add('newPassword', RepeatedType::class, [
    'type' => PasswordType::class,
    'mapped' => false,
    'options' => ['attr' => ['autocomplete' => 'new-password']],
]);
```

- The `password_policy` option of a field forces the check (`true`) or removes it (`false`).
- The help of the field describes the policy ("At least 12 characters, a lowercase letter, …"), unless the field
  has its own. `forms.help: false` removes it. In a template, `herse_password_policy()` returns the same rules as a
  list.
- `IQ2i\HerseBundle\Form\Type\PasswordPolicyType` is a ready-made repeated password field.

## Console commands

A question asking for a password takes the validator of the bundle: the question is asked again, with the broken
rules, until the password complies.

```php
use IQ2i\HerseBundle\Console\PasswordQuestionValidator;

$question = (new Question('Password: '))->setHidden(true)->setValidator($this->passwordQuestionValidator);
```

## Anywhere else

`IQ2i\HerseBundle\PasswordPolicy\PasswordPolicyChecker` checks a plain password in a service, an import or an API
handler: `violations($password)` returns the violations and `messages($password)` their translated messages.

DTOs and API payloads use the constraint without argument, it reads the configuration of the bundle:

```php
use IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy;

#[PasswordPolicy]
public ?string $newPassword = null;
```

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
