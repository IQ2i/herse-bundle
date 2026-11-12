# Emails

Every email of the bundle (new device, password change, two-factor code) goes through one internal sender based on
Symfony Mailer. The features sending emails need the packages below, and the container compilation fails with the
exact `composer require` command when one is missing.

## Installation

```bash
composer require symfony/mailer symfony/twig-bundle symfony/translation
```

Mailer must be configured in the framework (`framework.mailer`).

## Configuration

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    mailer:
        sender: no-reply@example.com   # required
        sender_name: Example           # optional
        app_name: Example shop         # optional, available as "app_name" in the templates
```

## Behaviour

- **Templates**: one Twig template per email, extending `@IQ2iHerse/email/layout.html.twig`. Override a template, or
  only the layout to apply your own design to every email, in `templates/bundles/IQ2iHerseBundle/email/`. The default
  rendering is deliberately neutral.
- **Translations**: English and French, in the `iq2i_herse` domain. The email uses the locale returned by the `locale`
  property path of the user class when it is declared, the default locale otherwise.
- **Recipient**: read from the `email` property path of the user class. When there is none, or when it is not a valid
  address, nothing is sent and a warning is logged.
- **Asynchronous sending**: nothing to configure. When Messenger routes the emails to a transport in your project, the
  emails of the bundle follow.
- **Failures**: an error while sending never fails a login or a password change, it is logged.
