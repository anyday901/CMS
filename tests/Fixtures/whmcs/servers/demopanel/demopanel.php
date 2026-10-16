<?php

// A WHMCS server module for the tests, written the way WHMCS's sample module is.
// Each call is recorded in $GLOBALS['demopanel_calls']; $GLOBALS['demopanel_replies']
// sets what a function returns (a Throwable is thrown).

use WHMCS\Database\Capsule;

if (! defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

function demopanel_MetaData()
{
    return ['DisplayName' => 'Demo Panel', 'APIVersion' => '1.1', 'RequiresServer' => true, 'DefaultSSLPort' => '2222', 'DefaultNonSSLPort' => '2221'];
}

function demopanel_ConfigOptions()
{
    return [
        'Package Name' => ['Type' => 'text', 'Size' => '25', 'Default' => 'basic', 'Description' => 'The <b>package</b> on the panel'],
        'Shell Access' => ['Type' => 'yesno', 'Description' => 'Tick to grant access'],
        'Region' => ['Type' => 'dropdown', 'Options' => 'eu,us', 'Default' => 'us'],
        'Notes' => ['Type' => 'textarea'],
    ];
}

function demopanel_reply(string $function, array $params)
{
    $GLOBALS['demopanel_calls'][] = [$function, $params];
    $reply = $GLOBALS['demopanel_replies'][$function] ?? 'success';

    if ($reply instanceof Throwable) {
        throw $reply;
    }

    return $reply;
}

function demopanel_CreateAccount(array $params)
{
    echo 'Output that WHMCS throws away';
    Capsule::table('mod_demopanel')->insert(['service_id' => $params['serviceid'], 'username' => $params['username']]);
    logModuleCall('demopanel', 'CreateAccount', $params, 'created', '', [$params['password']]);

    return demopanel_reply('CreateAccount', $params);
}

function demopanel_SuspendAccount(array $params)
{
    return demopanel_reply('SuspendAccount', $params);
}

function demopanel_TerminateAccount(array $params)
{
    return demopanel_reply('TerminateAccount', $params);
}

function demopanel_ChangePassword(array $params)
{
    return demopanel_reply('ChangePassword', $params);
}

function demopanel_TestConnection(array $params)
{
    return demopanel_reply('TestConnection', $params);
}

function demopanel_ServiceSingleSignOn(array $params)
{
    return demopanel_reply('ServiceSingleSignOn', $params);
}

function demopanel_AdminCustomButtonArray()
{
    return ['Reboot' => 'reboot'];
}

function demopanel_reboot(array $params)
{
    return 'success';
}

function demopanel_ClientArea(array $params)
{
    return ['templatefile' => 'clientarea'];
}
