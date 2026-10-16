# Themes

A theme changes how the client-facing pages look: the client portal, the store, the cart and checkout, and the public support pages. It can recolour everything with two colours, add a stylesheet, and replace any client-facing Blade view. The admin area always keeps the built-in look, so a broken theme can't lock staff out.

The built-in theme is **Harbor** (key `harbor`). It has no folder: it is the app's own views and CSS. An example theme, **Lighthouse**, ships in `themes/lighthouse` to copy from.

## Choosing a theme

Staff pick the active theme under **Settings > Appearance**. The same page has the white-label settings, which apply whichever theme is active:

- the logo;
- a brand colour, which overrides the theme's;
- the company name, address, email and tax id;
- the name of the admin area;
- hiding the "Powered by CMS" footer.

## What a theme contains

Themes live in `themes/{key}/` in the app's root folder:

```text
themes/lighthouse/
├── theme.json
├── assets/
│   └── theme.css
└── views/
    ├── partials/
    │   └── portal-footer.blade.php
    └── portal/
        └── dashboard.blade.php
```

### `theme.json`

```json
{
    "key": "lighthouse",
    "name": "Lighthouse",
    "description": "A light theme with a blue accent.",
    "version": "1.0.0",
    "author": "Your name",
    "colors": {
        "brand": "#2563eb",
        "ink": "#475569"
    },
    "stylesheet": "theme.css"
}
```

| Key | What it is |
|---|---|
| `key` | The theme's unique key, the same as its folder name |
| `name`, `description`, `version`, `author` | Shown under **Settings > Appearance** |
| `colors.brand` | One hex colour for buttons, links and highlights |
| `colors.ink` | One hex colour for text, borders and backgrounds |
| `stylesheet` | Optional. A CSS file in `assets/`, loaded after the app's CSS |
| `requires` | Optional. The oldest CMS version the theme works with, such as `1.0.0`; older installs refuse to use or install it |

### Colours

Views use two colour scales in their Tailwind classes: `brand` for buttons and links (such as `bg-brand-600`) and `ink` for text, borders and backgrounds (such as `text-ink-700` or `border-ink-200`). Each scale has the shades 50 to 950.

A theme gives one colour for each, and the app derives the shades from it with CSS `color-mix()`. So a theme recolours every page without rebuilding the CSS, and without listing eleven shades. Pick `brand` as the colour you'd use for a main button, and `ink` as a mid grey (or a tinted grey) for body text.

A brand colour set under **Settings > Appearance** wins over the theme's `colors.brand`.

### Assets

Files in `themes/{key}/assets/` are served at `/theme-assets/{key}/{file}`, so a stylesheet can refer to an image next to it with a relative URL. Only CSS, JavaScript, images and fonts are served.

The file named by `stylesheet` is loaded on client-facing pages after the app's own CSS, so its rules win. Use it for fonts, corner sizes, spacing and anything the two colours don't cover:

```css
/* themes/lighthouse/assets/theme.css */
@font-face {
    font-family: "Inter";
    src: url("inter.woff2") format("woff2");
}

body {
    font-family: "Inter", sans-serif;
}
```

The app's CSS only contains the Tailwind classes its own views use (and those in theme folders present when the assets are built with `npm run build`), so a class that appears only in an installed theme's views may not exist. Style those parts in the theme's stylesheet instead.

### Overriding views

A Blade file in `themes/{key}/views/` replaces the client-facing view at the same path under `resources/views`:

| Theme file | Replaces |
|---|---|
| `views/portal/dashboard.blade.php` | `resources/views/portal/dashboard.blade.php` |
| `views/components/portal-layout.blade.php` | the `<x-portal-layout>` component every portal page uses |
| `views/partials/portal-footer.blade.php` | the portal's footer partial |

Start from a copy of the app's view and change it. Keep the variables and form fields the original uses, so the controllers behind it keep working, and check your overrides after each update of CMS, since the app's views can change.

Admin views (`resources/views/admin/...`) can't be overridden.

`views/partials/portal-footer.blade.php` is a small partial that exists for themes, so a theme can add a footer (links, an address, a phone number) without replacing the whole layout.

## Site-specific overrides

`themes/overrides/views/` holds template overrides for your own site, such as a changed dashboard or footer. They apply on top of whichever theme is active, so you can switch themes, or update one, without losing them. The updater keeps this folder.

Put a change here rather than editing a theme you installed, or the app's own views, which updates replace.

## Translations in themes

Wrap text in a theme's views in `__()`, such as `{{ __('Your services') }}`, so it is translated with the rest of the portal. See [Translations](translations.md).

## Sharing a theme

A theme folder zipped up is a marketplace package. See [Marketplace packages](marketplace.md) for the zip layout, how to build one, and how to list it.
