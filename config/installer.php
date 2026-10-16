<?php

return [
    // The web installer at /install, which answers until the first staff account exists. Set to false to set up from the command line only.
    'enabled' => (bool) env('INSTALLER_ENABLED', true),
];
