<?php

namespace Tests\Fakes;

use RuntimeException;

/** Thrown by a deliberately broken hook listener in tests. */
final class ListenerFailed extends RuntimeException {}
