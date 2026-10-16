<?php

namespace App\System\Backups;

use RuntimeException;

/** A backup that couldn't be made or read. */
class BackupFailed extends RuntimeException {}
