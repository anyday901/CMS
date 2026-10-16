<?php

namespace App\System;

use RuntimeException;

/** A setup step that failed; the message is shown in the web installer. */
class InstallFailed extends RuntimeException {}
