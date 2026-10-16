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

Upload and enable your WHMCS modules before importing from WHMCS. Products and servers on a module enabled here then come over on it, with the product's module settings as they were, and services keep their server and login. An uploaded module is used before CMS's own module of the same name.
