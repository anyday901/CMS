<?php

namespace Tests\Fakes;

use RuntimeException;

/** Stands in for a control panel that can't be reached. */
class FakeModuleUnreachable extends RuntimeException {}
