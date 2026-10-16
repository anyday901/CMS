<?php

namespace App\Marketplace;

use RuntimeException;

/** A package that can't be built, installed or removed; the message is shown to staff. */
class PackageException extends RuntimeException {}
