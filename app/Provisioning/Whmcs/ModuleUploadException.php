<?php

namespace App\Provisioning\Whmcs;

use RuntimeException;

/** A WHMCS module that can't be uploaded, enabled or removed; the message is shown to staff. */
class ModuleUploadException extends RuntimeException {}
