<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Demo mode (config/demo.php): a public demo instance anyone can sign in to. */
final class Demo
{
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    /**
     * Stops a change the demo doesn't allow, with a message on the page.
     *
     * @throws ValidationException in demo mode
     */
    public static function refuse(): void
    {
        if (self::enabled()) {
            throw ValidationException::withMessages(['demo' => __("This is a demo, so this can't be changed here. Everything else works, and the demo resets itself every few hours.")]);
        }
    }
}
