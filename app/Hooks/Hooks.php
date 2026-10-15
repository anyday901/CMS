<?php

namespace App\Hooks;

use Closure;
use Throwable;

/**
 * The event hook system. Core events (an invoice paid, a ticket opened and
 * so on) are fired here with their data. Plugins and add-ons listen with
 * listen(), and can define events of their own with define(); outgoing
 * webhooks, chat channels and SMS are subscribers that see every event.
 *
 * A listener that throws is reported and skipped, so a broken plugin can't
 * stop a payment being recorded or another listener running.
 */
class Hooks
{
    /** Listens to every event. */
    public const ANY = '*';

    /** @var array<string, string> event key => label */
    private array $events;

    /** @var array<string, list<Closure>> */
    private array $listeners = [];

    public function __construct()
    {
        $this->events = HookCatalog::events();
    }

    /** Adds an event of a plugin's own, so webhooks and chat channels can subscribe to it. */
    public function define(string $key, string $label): void
    {
        $this->events[$key] = $label;
    }

    /** @return array<string, string> every event key with its label */
    public function events(): array
    {
        return $this->events;
    }

    public function has(string $key): bool
    {
        return isset($this->events[$key]);
    }

    /**
     * Calls $listener with each HookEvent for these keys (or Hooks::ANY).
     *
     * @param  string|list<string>  $keys
     * @param  callable(HookEvent): mixed  $listener
     */
    public function listen(string|array $keys, callable $listener): void
    {
        foreach ((array) $keys as $key) {
            $this->listeners[$key][] = Closure::fromCallable($listener);
        }
    }

    /** Fires an event: plugin listeners first, then webhooks, chat channels and SMS. */
    public function fire(HookEvent $event): void
    {
        foreach ([...$this->listeners[$event->key] ?? [], ...$this->listeners[self::ANY] ?? []] as $listener) {
            $this->safely(fn () => $listener($event));
        }

        foreach (config('integrations.subscribers', []) as $subscriber) {
            $this->safely(fn () => app($subscriber)->handle($event));
        }
    }

    private function safely(Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
