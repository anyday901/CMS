# Marketplace

**Settings > Marketplace** lists add-ons and themes you can install with one click. It needs the **Back up the database, install updates, themes and marketplace packages** permission (`manage-system`), which admins have.

- **Install** downloads the package over HTTPS, checks it against the checksum in the marketplace and unpacks it. An add-on then shows under **Settings > Add-ons**, to install and enable as usual; a theme shows under **Settings > Appearance**.
- **Update** appears when the marketplace has a newer version than the one installed.
- **Remove** deletes a package that was installed from the marketplace or uploaded. Switch away from a theme, or uninstall an add-on, first.
- **Upload a package** installs a zip you were given directly (up to 7.5 MB).

!!! warning "Only install packages you trust"
    Add-ons are PHP code that runs with full access to CMS and its data. Set `MARKETPLACE_INSTALLS=false` in `.env` to allow browsing only.

The list comes from `MARKETPLACE_REGISTRY_URL`, the official registry by default. To run your own registry, or to publish a package, see [Marketplace packages](../developers/marketplace.md).
