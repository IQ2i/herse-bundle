# Sylius

The bundle does not depend on Sylius and contains no Sylius-specific code. The compatibility rests on the
configuration per user class and per firewall, and is proven by a Sylius application of the test suite
(`tests/SyliusApplication`): its tests cover the password policy, the login throttling, the trusted devices, the
password change notification and the two-factor authentication, on the administration and the shop.

Supported: **Sylius 2.2** (PHP 8.2+) and **Sylius 2.3** (PHP 8.3+). The test suite runs on Sylius 2.2 with PHP 8.2
and on Sylius 2.3 with PHP 8.4. The names below are the ones of Sylius-Standard: a project that renamed its firewalls
or extended other classes adapts them.

## Installation

```bash
composer require iq2i/herse-bundle
```

Register the bundle in `config/bundles.php` if Symfony Flex did not:

```php
IQ2i\HerseBundle\IQ2iHerseBundle::class => ['all' => true],
```

Then install the packages of the features you enable, see the guide of each feature. The bundle does not use the
email system of Sylius: the security emails do not appear in its email configuration, they are configured under
`iq2i_herse.mailer` ([emails](emails.md)).

## Declaring the users

Sylius has two populations of users, so two types of user reference. A device trusted on the shop is not trusted on
the administration.

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    users:
        App\Entity\User\AdminUser:
            type: admin
            email: email
            locale: localeCode
            plain_password: { property: plainPassword, groups: [sylius_user_create] }
        App\Entity\User\ShopUser:
            type: shop
            email: customer.email          # the shop user has no address of its own
            plain_password: { property: plainPassword, groups: [sylius_user_registration] }
```

- The address of a shop user is the one of its customer, hence `customer.email`. A customer has no locale in Sylius:
  the emails use the default locale of the application.
- The hashed password is the `password` field of both classes, the default.

## Password policy

Sylius validates the plain password of the user entity with the groups `sylius_user_registration` (registration of a
customer) and `sylius_user_create` (creation of an administrator). The declaration above applies the policy in those
groups.

The change and the reset of a password do not validate the entity, they validate form models, in the group
`sylius`: `Sylius\Bundle\UserBundle\Form\Model\ChangePassword`, `Sylius\Bundle\UserBundle\Form\Model\PasswordReset` and
`Sylius\Bundle\AdminBundle\Form\Model\PasswordReset`. Apply the constraint to them with a validation mapping file,
which the framework loads from `config/validator/`:

```xml
<!-- config/validator/herse.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<constraint-mapping xmlns="http://symfony.com/schema/dic/constraint-mapping" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://symfony.com/schema/dic/constraint-mapping https://symfony.com/schema/dic/constraint-mapping/constraint-mapping-1.0.xsd">
    <class name="Sylius\Bundle\UserBundle\Form\Model\ChangePassword">
        <property name="newPassword">
            <constraint name="IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy">
                <option name="groups">
                    <value>sylius</value>
                </option>
            </constraint>
        </property>
    </class>
    <class name="Sylius\Bundle\UserBundle\Form\Model\PasswordReset">
        <property name="password">
            <constraint name="IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy">
                <option name="groups">
                    <value>sylius</value>
                </option>
            </constraint>
        </property>
    </class>
    <class name="Sylius\Bundle\AdminBundle\Form\Model\PasswordReset">
        <property name="password">
            <constraint name="IQ2i\HerseBundle\Validator\Constraints\PasswordPolicy">
                <option name="groups">
                    <value>sylius</value>
                </option>
            </constraint>
        </property>
    </class>
</constraint-mapping>
```

The policy comes on top of the rules of Sylius (a length between 4 and 254 characters), which stay in force. The
commands of the Sylius API (`RegisterShopUser`, `ChangeShopUserPassword`, `AccountResetPassword`,
`AdminResetPassword`) are validated by their own mappings: add the constraint to them the same way if you use the API.
The test suite does not cover them.

## Login throttling

One line per firewall, at least `admin` and `shop`:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        admin:
            login_throttling:
                limiter: iq2i_herse.login_throttling.limiter
        shop:
            login_throttling:
                limiter: iq2i_herse.login_throttling.limiter
```

The limits of the two firewalls are separate. The `api_admin` and `api_shop` firewalls can have the same line: they
are not covered by the tests.

## Trusted devices

Watch the two firewalls:

```yaml
iq2i_herse:
    trusted_devices:
        enabled: true
        firewalls:
            admin: ~
            shop: ~
```

The cookies are `herse_device_admin` and `herse_device_shop`. Generate the migration of the
`iq2i_herse_trusted_device` table with `bin/console doctrine:migrations:diff`. The `api_admin` and `api_shop`
firewalls authenticate with a token (JWT), so they are not covered: the registry relies on a browser cookie. The
remember-me of Sylius refreshes a known device and never registers one.

## Password change notification

Sylius hashes the password with its own updater (`sylius.security.password_updater`), called by its resource events
before the flush. The change is detected on the `password` column, so the notification works whatever the entry point:
a customer, an administrator changing the password of a customer, a console command.

Sylius never rewrites the hash of a user at login: its user providers do not implement `PasswordUpgraderInterface`.
Logging in with an outdated hash sends no password change email. If your project adds an upgrader, the write it makes
during the authentication is ignored by the bundle.

## Two-factor authentication

The interface of scheb goes on the extended entity of your project, here the administrator:

```php
// src/Entity/User/AdminUser.php
class AdminUser extends BaseAdminUser implements Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface
{
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $emailAuthEnabled = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $authCode = null;

    public function isEmailAuthEnabled(): bool { return $this->emailAuthEnabled; }
    public function getEmailAuthRecipient(): string { return (string) $this->getEmail(); }
    public function getEmailAuthCode(): ?string { return $this->authCode; }
    public function setEmailAuthCode(string $authCode): void { $this->authCode = $authCode; }
}
```

Add `two_factor` and a `logout` path to the firewall, and the access rule before the one of the administration:

```yaml
security:
    firewalls:
        admin:
            logout:
                path: sylius_admin_logout
            two_factor:
                auth_form_path: admin_two_factor_form
                check_path: admin_two_factor_check
                prepare_on_login: true
    access_control:
        - { path: "%sylius.security.admin_regex%/2fa", role: IS_AUTHENTICATED_2FA_IN_PROGRESS }
        - { path: "%sylius.security.admin_regex%", role: ROLE_ADMINISTRATION_ACCESS }
```

The rest is described in the [two-factor guide](two-factor.md). The test suite covers the administration; the shop
firewall is left without second factor there. The administration and the shop have their own login screens: their
code forms are routes of your project (`auth_form_path`), so each firewall can render its own template.

## Running the Sylius tests

```bash
make sylius-install                      # SYLIUS_VERSION=2.2 for the other version
make sylius-test
```

The application is minimal: no admin or shop screens, no assets. Its firewalls keep the names, providers, user
checkers and contexts of Sylius-Standard, with a JSON login and no CSRF token to stay independent of the screens.
It runs on SQLite, which Sylius does not officially support; the schema is created from the Doctrine metadata.
