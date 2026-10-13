<?php

namespace App\Provisioning;

use RuntimeException;

/** An error reported by the other system, shown to staff as the action's failure. */
class ModuleError extends RuntimeException {}
