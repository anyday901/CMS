<?php

namespace App\Addons;

use RuntimeException;

/** An add-on's migrations failed while installing, upgrading or uninstalling it. */
class AddonMigrationFailed extends RuntimeException {}
