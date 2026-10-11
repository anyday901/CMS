<?php

namespace App\Import\Whmcs;

use RuntimeException;

/** A WHMCS import or comparison that can't go ahead, with a message for the person running it. */
class ImportException extends RuntimeException {}
