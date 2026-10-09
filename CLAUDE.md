# CMS

Open source, self-hosted WHMCS alternative built on Laravel 13 (PHP 8.3+).

- The product spec is `docs/spec.md`. Check it before adding features; v1 scope is deliberately narrow.
- Run `php artisan test` before committing. CI runs the same on PHP 8.3 to 8.5.
- Money is stored as integer minor units (cents), never floats.
- Payment gateways and provisioning modules are plugins behind interfaces; keep gateway-specific code out of the billing core.
- License is AGPL-3.0-or-later.
- Every change updates `CHANGELOG.md` (Keep a Changelog format, under Unreleased), `TODO.md` (tick or add items) and `README.md` when setup or features change.
