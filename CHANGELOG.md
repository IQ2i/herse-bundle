# Changelog

The changes of each release. A change of behaviour is marked **BC**.

## Unreleased

- **BC** The entity `TrustedDevice` moves to `IQ2i\HerseBundle\TrustedDevice\Entity`. With the `auto_mapping` of
  Doctrine (Symfony recipe, Sylius), its table was proposed to every project, even without the trusted devices. The
  table and the columns do not change: no migration.
- `two_factor.issuer` sets the name shown in the authenticator app, from an environment variable if needed. It
  defaults to `mailer.app_name`.
- A second factor code that was accepted is refused a second time while it is still valid
  (`two_factor.code_reuse_cache`, with `scheb/2fa-bundle` 7.10 or later). A user logging in on a second device waits
  for the next code.
- The errors of Symfony shown on the second factor form, as the one of the throttling, are translated.
- `two_factor.reset_role` with `method: email` fails the container compilation: it was silently ignored.
- The conflicting versions of the optional packages (scheb, Doctrine, EasyAdmin, the QR code and OTP libraries) are
  declared: Composer refuses the ones the bundle is not tested with.
- `symfony/event-dispatcher`, `symfony/http-foundation` and `symfony/password-hasher` are required explicitly.
- A complete [configuration reference](docs/configuration.md), with the Twig functions.

## 0.2.0 - 2026-11-13

A project configured for 0.1 keeps working, see
[what you can delete](docs/upgrading-an-existing-project.md#from-01-to-02).

- **BC** The hash guard is on by default: with `password_policy` enabled, hashing a password that breaks the policy
  throws a `PasswordPolicyViolationException`. Set `password_policy.hash_guard: false` under `when@test`, or wrap the
  code in `PasswordPolicyGuard::withoutGuard()`.
- **BC** The default second factor form is the one of the bundle, in place of the demo template of scheb.
- `iq2i_herse.firewalls` declares the firewalls the features protect. Their `security.yaml` is checked: the
  container compilation fails with the lines to add.
- Password policy: the hash guard, the check of every `PasswordType` field with `autocomplete="new-password"`,
  `PasswordQuestionValidator`, `PasswordPolicyChecker`, `PasswordPolicyDescriber` and `herse_password_policy()`.
- Two-factor authentication: the entity traits, the routes of each firewall, the second factor form, the TOTP
  enrollment screen, the mandatory second factor (`two_factor.required`), the reset of a lost authenticator app
  (command, screen, `TwoFactorResetter`, `herse_two_factor_reset_path()`, EasyAdmin action).
- Trusted devices: the "my devices" screen on each declared firewall.
- Screens: placed in the layout of the project, made of overridable blocks, with a theme per firewall. English and
  French texts.
- Events: `TwoFactorEnabledEvent`, `TwoFactorResetEvent` and `TrustedDevicesRevokedEvent`.
- Tests: `TwoFactorTestTrait` passes the second factor in the functional tests of a project.

## 0.1.0 - 2026-11-13

Initial release: password policy, login throttling, trusted devices, password change notification and two-factor
authentication, with a skill for Symfony Mate.
