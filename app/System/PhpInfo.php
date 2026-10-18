<?php

namespace App\System;

/**
 * What Settings > PHP info shows: a summary of the PHP that runs CMS, and
 * phpinfo() itself without the environment and request variables, which
 * hold .env secrets such as the app key and database password.
 */
class PhpInfo
{
    /** The ini settings worth checking first, with what each one is for. */
    public const SETTINGS = [
        'memory_limit' => 'Memory a request can use',
        'max_execution_time' => 'Seconds a web request can run',
        'max_input_time' => 'Seconds to read a request',
        'max_input_vars' => 'Form fields per request',
        'post_max_size' => 'Largest form post',
        'upload_max_filesize' => 'Largest uploaded file',
        'max_file_uploads' => 'Files per upload',
        'date.timezone' => 'Default timezone',
        'display_errors' => 'Errors shown on the page',
        'log_errors' => 'Errors written to the log',
        'allow_url_fopen' => 'Opening URLs as files',
        'opcache.enable' => 'OPcache',
        'disable_functions' => 'Disabled functions',
    ];

    /** @return array{version: string, sapi: string, os: string, zend: string, ini: ?string, settings: array<string, array{label: string, value: string}>, extensions: list<string>} */
    public function summary(): array
    {
        $settings = [];
        foreach (self::SETTINGS as $key => $label) {
            $value = ini_get($key);
            $settings[$key] = ['label' => $label, 'value' => $value === false ? 'Not available' : ($value === '' ? 'Not set' : $value)];
        }

        $extensions = get_loaded_extensions();
        natcasesort($extensions);

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => php_uname('s').' '.php_uname('r').' ('.php_uname('m').')',
            'zend' => zend_version(),
            'ini' => php_ini_loaded_file() ?: null,
            'settings' => $settings,
            'extensions' => array_values($extensions),
        ];
    }

    /**
     * phpinfo()'s output as HTML to put inside a page: the body of its own
     * page when PHP runs under a web server (which escapes every value), or
     * the escaped text in a <pre> on the command line, such as in tests.
     */
    public function html(): string
    {
        ob_start();
        phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
        $output = (string) ob_get_clean();

        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $output, $match)) {
            return $match[1];
        }

        return '<pre>'.e($output).'</pre>';
    }
}
