# Password change notification

The owner of an account receives an email when the hashed password of a declared user class changes. The change is
detected by Doctrine, so profile pages, reset links, administrators and console commands are all covered without any
call in your code. The email is sent once the flush has succeeded, never before.

## Installation

```bash
composer require doctrine/orm doctrine/doctrine-bundle symfony/mailer symfony/twig-bundle symfony/translation
```

The mailer is configured as described in the [emails](emails.md) guide. No table is needed.

## Configuration

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    users:
        App\Entity\User:
            type: shop
            email: email
            password: password           # the field of the hashed password, "password" by default
    mailer:
        sender: no-reply@example.com
    password_change_notification:
        enabled: true
        notify_on_other_user: true   # default: another authenticated user (an administrator) changed it
        notify_on_console: true      # default: changed outside of a web request (console command, worker)
```

The hashed password must be a field mapped by Doctrine, otherwise a `LogicException` is thrown on the first update of
such a user.

The email gives the date and time of the change and tells the user what to do if they did not make it. It contains no
reset link, on purpose.

## Cases that send nothing

| Case                           | Rule                                                                                       |
| ------------------------------ | ------------------------------------------------------------------------------------------ |
| Account creation               | Nothing is sent on insertion.                                                              |
| First password of an account   | Nothing is sent when the previous value is empty.                                          |
| Password removed               | Nothing is sent when the new value is empty.                                               |
| Automatic rehash at login      | Changes made between the credentials check and the end of the login are not user changes.  |
| Fixtures and imports           | Switch the notification off around the processing, see below.                              |

A password reset followed by an automatic login is still notified: the password is written before the authentication
starts.

## Fixtures and imports

```php
use IQ2i\HerseBundle\PasswordChange\PasswordChangeNotification;

$notification->withoutNotification(function () use ($importer): void {
    $importer->import();
});
// or $notification->disable() then $notification->enable(), which can be nested
```

A suspension ends with the request or the message being handled: a long-running worker starts the next one with the
notification on, even when `enable()` was never called.

## Event

`IQ2i\HerseBundle\Event\PasswordChangedEvent` is dispatched before the email is sent. It carries the `UserReference`,
the user object, the date of the change, the context and the email itself. A listener can call `cancel()` to prevent
the sending, or `setEmail()` to replace the message. The context (`PasswordChangeContext`) is:

- `Self` for a web request made by the owner or by somebody with no authenticated user (a reset link);
- `OtherUser` for a web request made by another authenticated user, including one impersonating the owner
  (`switch_user`);
- `Console` when there is no web request.

## Limits

- Only the changes flushed by the entity manager are detected: a password written with a DQL `UPDATE` or straight
  through the connection sends nothing.
- The email is sent once the flush has succeeded. When your code wraps the flush in its own transaction and rolls it
  back afterwards, the email has already been sent.

## Checking the installation

Change the password of a test account from your profile page: the email arrives once the flush is done. Run the same
change from a console command: with the default options the email is sent too.
