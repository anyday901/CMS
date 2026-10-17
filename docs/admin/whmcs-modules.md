# WHMCS modules

**Settings > WHMCS modules** runs WHMCS server (provisioning) modules in CMS. Upload the module, read the check, enable it, then choose it on products like any built-in module. Servers for it are added under **Products > Servers** as usual.

Only staff with the **system** ability see the page. Set `WHMCS_MODULE_UPLOADS=false` in `.env` to turn uploading and enabling off; modules already enabled keep working. Uploaded modules are kept in `extensions/whmcs/servers` (`WHMCS_MODULES_PATH`).

!!! warning
    A module is PHP code that runs with full access to your data, like the rest of CMS. Only upload modules you trust, from their developer.

## Uploading

Upload either:

- the module's PHP file, such as `mypanel.php`, for a module that is one file;
- a zip of its folder (`mypanel/mypanel.php` with anything else it needs); or
- a zip of WHMCS's `modules` folder with it in `modules/servers/mypanel`. If the zip also has an add-on or other module of the same product, only the server module is installed and the check says so.

Each upload is checked without running it. These are refused, with the reason:

- payment gateway, registrar and add-on modules, which CMS can't run yet;
- encoded modules (ionCube, SourceGuardian), whose code can't be checked. Most paid modules are encoded, and many check a WHMCS licence too, so ask the developer for an unencoded copy;
- files with PHP errors, and files that have no `{name}_CreateAccount` function.

A new module stays off. Its card lists the functions CMS uses, the ones it doesn't (custom buttons, client area pages and other WHMCS-only functions), and anything in the code that won't work here:

- reading WHMCS's own tables (`tblhosting`, `tblclients` and so on), which don't exist in CMS. A module's own tables (`mod_...`) work;
- `localAPI`, which always returns an error, and the old `select_query`-style database functions;
- `encrypt()` and `decrypt()`, which are Laravel's here and can't read values WHMCS encrypted;
- WHMCS classes other than `WHMCS\Database\Capsule`, and files loaded from `ROOTDIR`;
- `hooks.php` and client area templates (`.tpl`), which aren't loaded.

Uploading a module again replaces its files. If it is enabled, it is loaded again first, and the old files are kept if the new ones fail; queue workers are told to restart so they pick up the new code.

## Enabling

**Enable** loads the module in a separate PHP process and reads its `MetaData` (its name, whether it uses servers, its default ports) and `ConfigOptions`. If it fails to load, it stays off and the error is shown. Its config options become the product's module settings, in the same order WHMCS saves them (`configoption1` to `configoption24`): text and password options are text boxes, yes/no options and dropdowns are lists, and text areas stay text areas.

For modules that use servers, **Orders ask for a domain name** decides whether the store asks for a domain with the product (on by default, as for hosting). A new service gets a username from its domain and a random password before `CreateAccount`, as in WHMCS.

A module used by a product or a server can't be turned off or removed.

## Converting to a CMS module

Once an enabled module works, **Convert to a CMS module** turns it into a CMS module class, so it can be kept with your site and changed like the built-in modules. The conversion is mechanical:

- each `mypanel_Function` becomes a method of a `MypanelModule` class: `CreateAccount` becomes `createAccount`, `SuspendAccount`, `UnsuspendAccount` and `TerminateAccount` keep their names, `ChangePassword` becomes `changeAccountPassword`, `ChangePackage` `changeAccountPackage`, `TestConnection` `checkConnection` and `ServiceSingleSignOn` `singleSignOn`. The module's other functions become private methods, and calls between them become `$this->method(...)`;
- the settings get names from their labels (`Plan` becomes `plan`), so `$params['configoption1']` becomes `$params['plan']`. `configoption1` to `configoption24` are still passed too;
- `WHMCS\Database\Capsule` becomes Laravel's `DB` (and `Schema` for `Capsule::schema()`), `logModuleCall` and `logActivity` become the class's own methods, and the `defined('WHMCS')` guard is dropped;
- the module's name, settings, ports and **Orders ask for a domain name** become methods of the class.

The code inside the functions is copied as it is, so anything the upload check warned about (such as reading `tblhosting` or calling `localAPI`) still needs a person to rewrite it; the class's header lists those warnings. A module whose main file declares a namespace, or has code outside its functions that calls them, can't be converted and keeps working as an uploaded module.

Before anything changes, the new class is loaded in a separate PHP process. Then products on the module move to the converted one with their settings renamed, servers move with them, and the uploaded WHMCS module is removed. Converted modules are kept in `extensions/modules/{name}` (`CONVERTED_MODULES_PATH`): the class, the module's other files, and a `module.json`. **Download** gives you that folder as a zip, to keep in version control or copy to another install's `extensions/modules`. A converted module used by a product or a server can't be removed.

## What the module gets

Each action calls the module's function with the `$params` WHMCS would pass:

| CMS action | WHMCS function |
| --- | --- |
| Create | `CreateAccount` |
| Suspend, unsuspend, terminate | `SuspendAccount`, `UnsuspendAccount`, `TerminateAccount` |
| Upgrade or downgrade | `ChangePackage` |
| New password | `ChangePassword` |
| Test connection on the server | `TestConnection` |
| Log in to the control panel | `ServiceSingleSignOn` |

`$params` has the service (`serviceid`, `domain`, `username`, `password`, `status`), the product's `configoption1` to `configoption24`, `customfields` and `configoptions` by name, `clientsdetails`, and the server (`serverhostname`, `serverip`, `serverusername`, `serverpassword`, `serveraccesshash` from the server's API token, `serversecure`, `serverport`). A return of `"success"` means it worked; anything else is shown to staff as the error. A function the module doesn't have does nothing, except `CreateAccount`.

`logModuleCall` writes to the app log at debug level with the values it is told to hide masked, and `logActivity` writes to the activity log.

## The WHMCS import

Upload and enable (or convert) your WHMCS modules before importing from WHMCS. Products and servers on a module enabled or converted here then come over on it, with the product's module settings (by name, for a converted module), and services keep their server and login. An uploaded or converted module is used before CMS's own module of the same name.
