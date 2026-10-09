<?php

namespace App\Payments;

use RuntimeException;

/** A gateway declined or could not complete a payment. The message is safe to show clients. */
class PaymentFailed extends RuntimeException {}
