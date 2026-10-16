<?php

namespace App\System;

use RuntimeException;

/** A change to .env that couldn't be made. */
class EnvFileException extends RuntimeException {}
