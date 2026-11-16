# Security Policy

## Supported versions

Herse is in its `0.x` series: the security fixes go to the latest release only.

## Reporting a vulnerability

Do not open a public issue for a security problem.

Report it through [GitHub's private vulnerability reporting](https://github.com/IQ2i/herse-bundle/security/advisories/new),
or by email to <contact@iq2i.com>. You will get an acknowledgement within a few working days.

## Scope

The bundle protects the accounts of the applications using it. The problems that matter most here are the ones
weakening that protection: a second factor, a login limit or a password policy that can be bypassed, a trusted
device that can be forged or that survives its revocation, a secret or a code leaking in a log, an email or an event.

The limits the documentation states, as the TOTP secret stored in clear text or the absence of backup codes, are
known: an improvement is welcome as a regular issue.
