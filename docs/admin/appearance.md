# Appearance and white-label

**Settings > Appearance** controls how the client portal, store and public support pages look, and whose name is on them. It needs the **Manage settings** permission.

## The theme

Pick the portal theme from the list. CMS ships two:

- **Harbor**, the built-in look (sea green and warm sand);
- **Lighthouse**, a blue example theme that also replaces the portal footer with your company's details.

Themes installed from the [marketplace](marketplace.md), or copied into `themes/` on the server, appear in the same list. The admin area always keeps the built-in look, so a broken theme can't lock staff out. To build a theme, see [Themes](../developers/themes.md) in the developer guide.

## White-label settings

These apply whichever theme is active:

| Setting | Where it shows |
|---|---|
| **Logo** | The portal and admin headers, emails and PDF invoices. PNG, JPG, GIF, WebP or SVG, up to 2 MB. SVG logos are left off PDFs. |
| **Brand colour** | Buttons, links and highlights everywhere, including emails. It wins over the theme's colour; untick it to use the theme's again. |
| **Company name, address, email, tax id** | The portal header, emails, invoices and the Lighthouse footer. These are the same as the `BILLING_COMPANY_*` settings in `.env`, which they replace once saved. |
| **Admin area name** | The admin header and page titles, such as "Example Hosting Admin". |
| **Hide "Powered by CMS"** | Removes the line at the bottom of portal pages. |

## Template overrides

To change a single portal page or the footer for your own site, put a Blade file in `themes/overrides/views/` at the same path as the view it replaces under `resources/views/`. Overrides apply on top of any theme, the updater keeps them, and the Appearance page lists the ones in place. See [Site-specific overrides](../developers/themes.md#site-specific-overrides).
