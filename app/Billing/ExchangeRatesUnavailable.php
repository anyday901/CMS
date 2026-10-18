<?php

namespace App\Billing;

use RuntimeException;

/** The European Central Bank's rates couldn't be fetched or read, so no rates were changed. */
class ExchangeRatesUnavailable extends RuntimeException {}
