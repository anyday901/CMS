# Marketplace

The add-ons and themes listed under **Settings > Marketplace** in every CMS install.

- `registry.json` lists each package: its type, key, description, author and homepage. Versions, download links and checksums are filled in when the registry is built.
- `packages/{key}/` holds each package's files, with its `addon.json` or `theme.json` at the top.

The docs workflow builds the zips and the published `registry.json` with:

```bash
php artisan marketplace:registry marketplace _site/marketplace --base-url=https://anyday901.github.io/CMS/marketplace
```

To list a package, open a pull request that adds its folder under `packages/` and an entry in `registry.json`. See the [marketplace guide](../docs/developers/marketplace.md).
