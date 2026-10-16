<?php

namespace App\System;

use RuntimeException;

/** An update that stopped; the message is shown on the updates page. */
class UpdateFailed extends RuntimeException {}
