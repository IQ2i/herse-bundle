# Login throttling

The bundle provides a login limiter combining up to three limits, each scoped to the firewall: failed attempts
per identifier and IP, per IP and, optionally, per identifier alone. Each firewall plugs it in with the native
`login_throttling` option.

## Installation

```bash
composer require symfony/rate-limiter
```

## Configuration

```yaml
# config/packages/iq2i_herse.yaml
iq2i_herse:
    login_throttling:
        enabled: true
        identifier_and_ip: { limit: 5, interval: '1 minute' }     # defaults
        ip: { limit: 25, interval: '1 minute' }                   # defaults
        identifier: { enabled: false, limit: 20, interval: '15 minutes' }   # disabled by default
        cache_pool: cache.rate_limiter     # default
        lock_factory: auto                 # lock.factory when it exists, null to disable
```

Then add one line per firewall in `security.yaml`, as the bundle cannot complete the firewall configuration itself:

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

One service serves every firewall: the cache keys include the name of the firewall.

## Points of attention

- The identifier limit covers an attack distributed over many IPs on one account, but it lets a third party
  temporarily lock someone else's account: enable it knowingly.
- The identifier is trimmed and lowercased before it is used, and neither the identifier nor the IP is readable in
  the cache keys.
- A successful login clears the identifier limits, never the IP one. It relies on the native listener, which does
  not do it on older Symfony versions (`symfony/security-http` 6.3.6 for instance).
- A blocked attempt still consumes a token of the other limits, as in the native limiter.
- The counters live in the application cache: on a multi-server project, use a shared cache pool.
- Behind a reverse proxy, the IP is only right when the `trusted_proxies` framework option is configured.

## Event

`IQ2i\HerseBundle\Event\LoginThrottledEvent` is dispatched when a failed attempt exhausts a limit (once per window
and limit), with the limit (`LoginThrottlingLimit`), the normalized identifier, the IP and the firewall.

## Checking the installation

Fail to log in more times than the threshold (5 times with the same identifier from the same IP by default): the
next attempt is refused even with the right password, and `LoginThrottledEvent` is dispatched once.
