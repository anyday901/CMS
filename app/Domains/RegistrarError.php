<?php

namespace App\Domains;

use RuntimeException;

/** A registrar refused a request or couldn't be reached. The message is for staff. */
class RegistrarError extends RuntimeException {}
