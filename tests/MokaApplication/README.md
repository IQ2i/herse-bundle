# MokaCMS test application

Proves that the Herse bundle runs on a MokaCMS project (`moka/cms` 1.1.2). MokaCMS is private: **this branch must
never be pushed**.

## Running it

MokaCMS needs PHP 8.2 or 8.3 and its sources on the machine (`MOKA_PATH`, `../../MokaCMS/moka` by default, see
`compose.override.yaml`).

```bash
make moka-build
make moka-install
make moka-test
```

## What a Moka project has to add

Nothing changes in MokaCMS nor in the bundle. The project needs Symfony 6.4.

| Feature | In this application |
|---|---|
| User declaration | `config/packages/herse.yaml`: `Moka\Cms\Entity\User\AbstractUser`, not the generated class, which does not exist when the container is compiled |
| Login throttling | `symfony/rate-limiter` and `login_throttling` on the `moka` firewall, `config/packages/security.yaml` |
| Password change notification | configuration only |
| Password policy | `src/Form/ResetPasswordTypeExtension.php`, on the only screen of Moka that sets a password. `moka:create-user` is not covered |
| Trusted devices | configuration, `src/Controller/DeviceController.php`, `templates/admin/devices.html.twig`, `src/EventSubscriber/AdminMenuSubscriber.php` |
| Second factor | `src/Moka/TwoFactorUserClassDumper.php` and `src/Moka/TwoFactorUserMapper.php` replace two Moka services (`config/services.yaml`), `src/Security/TwoFactorTrait.php`, `config/packages/test_two_factor/`, `templates/security/two_factor.html.twig` |
