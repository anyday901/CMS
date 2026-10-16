# Marketplace packages

The marketplace lets staff install add-ons and themes from a list, without copying files onto the server by hand. Under **Settings > Marketplace**, CMS shows the packages in a registry: a JSON file with each package's details and where to download it.

!!! warning "Only install packages you trust"
    A package is PHP code (an add-on) or Blade templates (a theme) that run with full access to the app and its database. Installing from the marketplace needs the **manage-system** staff permission. Set `MARKETPLACE_INSTALLS=false` in `.env` to turn installing off altogether.

## Installing a package

1. Under **Settings > Marketplace**, pick a package and press **Install**.
2. CMS downloads its zip over HTTPS, checks the zip's SHA-256 against the registry, and unpacks it:
    - an add-on into `extensions/addons/{key}/`;
    - a theme into `themes/{key}/`.
3. An add-on then appears under **Settings > Add-ons**, to install, configure and enable as usual (see [Add-ons](../addons.md)). A theme appears under **Settings > Appearance**.

Staff can also upload a package zip by hand on the same page, for a package that isn't in the registry.

## The registry

The registry's address is the `marketplace.registry_url` config value, set with `MARKETPLACE_REGISTRY_URL` in `.env`. It defaults to the official registry:

```text
https://anyday901.github.io/CMS/marketplace/registry.json
```

Point it at a registry of your own to offer only the packages you choose, such as your company's private add-ons. The format is:

```json
{
    "generated_at": "2026-10-15T12:00:00+00:00",
    "packages": [
        {
            "type": "addon",
            "key": "status",
            "name": "Status board",
            "description": "Shows paid invoices on a TV.",
            "author": "Acme",
            "homepage": "https://github.com/acme/cms-status",
            "version": "1.0.0",
            "requires": "1.0.0",
            "download_url": "https://example.com/packages/status-1.0.0.zip",
            "sha256": "4f6c0b3e..."
        }
    ]
}
```

| Field | What it is |
|---|---|
| `type` | `addon` or `theme` |
| `key` | The add-on's or theme's key, which is also its folder name |
| `name`, `description`, `author`, `homepage` | Shown in the list |
| `version` | The package's version |
| `requires` | The oldest CMS version it works with, such as `1.0.0` |
| `download_url` | The zip's address, over HTTPS |
| `sha256` | The SHA-256 of the zip, in hex |

## Package zips

A package zip holds the package's files either at the top level of the zip or inside a single folder.

### Add-ons

An add-on package has an `addon.json` next to its code:

```text
status/
├── addon.json
├── src/
│   ├── StatusAddon.php
│   └── BoardController.php
├── database/migrations/
├── resources/views/
└── lang/
```

```json
{
    "key": "status",
    "name": "Status board",
    "version": "1.0.0",
    "class": "Acme\\Status\\StatusAddon",
    "autoload": {
        "psr-4": {
            "Acme\\Status\\": "src/"
        }
    },
    "requires": "1.0.0"
}
```

- `class` is the add-on's class, which extends `App\Addons\Addon` (see [Add-ons](../addons.md)).
- `autoload` maps the add-on's PHP namespace to its folder, as in Composer's `psr-4`, so CMS can load its classes without running Composer.
- `key` must match what the class's `key()` returns.

Paths the add-on returns, such as `migrationsPath()` and `viewsPath()`, should be relative to its own files (`__DIR__.'/../database/migrations'`), since it is unpacked into `extensions/addons/{key}/`.

### Themes

A theme package is the theme's folder with its `theme.json` (see [Themes](themes.md)):

```text
lighthouse/
├── theme.json
├── assets/
└── views/
```

## Building a package

```sh
php artisan marketplace:package {path}
```

zips the package folder at `{path}` and prints the zip's SHA-256, for the registry's `sha256` field.

```sh
php artisan marketplace:registry marketplace public-site --base-url=https://example.com/marketplace
```

builds every package under `marketplace/packages/` into `public-site/packages/{key}-{version}.zip` and writes `public-site/registry.json`, starting from `marketplace/registry.json`, which lists the packages without `download_url` and `sha256`. The command fills those in for each zip it builds. `--base-url` is the HTTPS address the output folder will be published at, so each download is `{base-url}/packages/{key}-{version}.zip`. Publish the output folder there and point `MARKETPLACE_REGISTRY_URL` at `{base-url}/registry.json`.

## Listing a package in the official registry

Open a pull request on [anyday901/CMS](https://github.com/anyday901/CMS) that:

1. adds the package's folder under `marketplace/packages/`;
2. adds its entry to `marketplace/registry.json` (without `download_url` and `sha256`).

Once it is merged, the **Docs and marketplace** workflow rebuilds the published registry with the package in it. Being listed doesn't make a package safe to install: staff should still only install what they trust.
