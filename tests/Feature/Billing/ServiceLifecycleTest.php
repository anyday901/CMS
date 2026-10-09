<?php

namespace Tests\Feature\Billing;

use App\Billing\ServiceLifecycle;
use App\Events\ServiceSuspended;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_not_fired_when_the_transaction_rolls_back(): void
    {
        $fired = 0;
        Event::listen(ServiceSuspended::class, function () use (&$fired) {
            $fired++;
        });
        $service = Service::factory()->create();

        try {
            DB::transaction(function () use ($service) {
                app(ServiceLifecycle::class)->suspend($service, 'test');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $fired);

        DB::transaction(fn () => app(ServiceLifecycle::class)->suspend($service, 'test'));

        $this->assertSame(1, $fired);
    }
}
