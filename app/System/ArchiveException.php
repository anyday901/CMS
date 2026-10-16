<?php

namespace App\System;

use RuntimeException;

/** A zip that is damaged, too big or unsafe to unpack. */
class ArchiveException extends RuntimeException {}
