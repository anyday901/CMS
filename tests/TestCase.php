<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use InvalidArgumentException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Asserts $call is refused with an InvalidArgumentException whose message contains $message. */
    protected function expectsProblem(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected: {$message}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
