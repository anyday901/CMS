<?php

namespace App\Integrations\Sms;

use RuntimeException;

/** A text message the provider refused or couldn't take. */
class SmsFailed extends RuntimeException {}
