# Translations

The client-facing pages and client emails can be shown in several languages. Each client gets their own: the language they picked in the portal, or the one staff set on their account.

## How the language is chosen

- **Anyone** can pick a language with the picker at the bottom of portal pages; that choice wins for their visit.
- **Signed-in clients** otherwise see the portal in their account's language: the last one they picked, or the one staff set on the client.
- **Visitors** (the store, the public support pages, the login page) otherwise get their browser's language, when it is one of the languages offered.
- **Emails to a client** are sent in the client's language.
- Otherwise the main language is used.

Staff choose which languages are offered under **Settings**, in the **Languages** section.

## Portal text

Client-facing text uses Laravel's JSON translation strings: the English text is the key, wrapped in `__()`:

```blade
<h1>{{ __('Your services') }}</h1>
<p>{{ __('Invoice #:number is due on :date.', ['number' => $invoice->number, 'date' => $invoice->due_date->format('M j, Y')]) }}</p>
```

The language files are in `lang/` in the app's root folder:

| File | What it is |
|---|---|
| `lang/en.json` | The catalogue of every client-facing string |
| `lang/de.json`, `lang/fr.json`, `lang/es.json` | The German, French and Spanish translations that ship with CMS |
| `lang/{code}/emails.php` | Default email subjects and bodies for that language (see [Email templates](#email-templates)) |
| `lang/{code}/validation.php`, `pagination.php`, `passwords.php` | Laravel's form validation, pagination and password reset messages in that language (German, French and Spanish ship; anything missing falls back to English) |

Each JSON file maps the English string to its translation:

```json
{
    "Your services": "Ihre Dienste",
    "Invoice #:number is due on :date.": "Rechnung Nr. :number ist am :date fällig."
}
```

Keep placeholders such as `:number` exactly as they are. A string missing from a language falls back to English.

### Adding a language

1. Copy `lang/en.json` to `lang/{code}.json`, such as `lang/it.json`, and translate the values.
2. Optionally add `lang/{code}/emails.php` with the email templates in that language, and `lang/{code}/validation.php`, `pagination.php` and `passwords.php` with Laravel's messages (copy them from `lang/de/` and translate the values).
3. List the code under **Settings > Languages > Other languages**.

`php artisan lang:check` (or `php artisan lang:check it` for one language) lists the strings each language file is missing compared with `lang/en.json`, strings it has that English doesn't, placeholders that don't match, and email templates missing from `emails.php`. It exits with an error while anything is missing, so it can run in CI. Run it after adding strings, and before sending a translation as a pull request.

### Changing a string without editing files

Under **Settings > Portal text and translations**, staff can override any string for each language, including languages CMS doesn't ship a file for, such as a different word for "services". Overrides are saved in the database and win over the language files, so they survive updates.

## Email templates

`lang/{code}/emails.php` gives the default subject and body of each email template in that language. Staff can edit each template per language under **Email templates**, with a tab per language offered; an edited template wins over the file's default. Dates in emails are written in the client's language too. A client's emails use their language's template, and the main language's when there is none.

## Strings in add-ons and themes

Wrap every client-facing string in `__()`, with the English text as the key, in views and in PHP:

```php
return back()->with('status', __('Your board was saved.'));
```

Add-ons and themes can ship their own translations:

- **Add-ons** override `Addon::langPath()` to return a folder of JSON files, one per language (`de.json`, `fr.json` and so on). They are merged into the catalogue while the add-on is installed.

    ```php
    public function langPath(): ?string
    {
        return __DIR__.'/../lang';
    }
    ```

- **Themes** keep their strings in `__()` too. Strings that are already in the app's catalogue are translated with no more work.

Avoid building sentences from pieces (`__('Paid').' '.$date`): word order differs between languages. Use one string with placeholders instead.
