## Herse

This project uses Herse (`iq2i/herse-bundle`), the account security building blocks configured under `iq2i_herse`: password policy, login throttling, trusted devices, password change notification and two-factor authentication. Every feature is disabled until it is enabled in `config/packages/iq2i_herse.yaml`.

Load the `herse` skill before installing, configuring or changing any of them. Read the configuration of the project with `bin/console debug:config iq2i_herse` and the available options with `bin/console config:dump-reference iq2i_herse`, instead of guessing them.

A protection is never disabled, loosened or bypassed to make a test or a login pass: fix the cause, or ask the user.
