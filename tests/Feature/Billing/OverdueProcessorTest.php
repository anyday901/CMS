<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceGenerator;
use App\Billing\OverdueProcessor;
use App\Enums\ServiceStatus;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OverdueProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function runOn(string $date): array
    {
        return app(OverdueProcessor::class)->run(CarbonImmutable::parse($date));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.suspend_after_days' => 3, 'billing.terminate_after_days' => 30]);
    }

    public function test_suspends_then_terminates_unpaid_services(): void
    {
        Event::fake([ServiceSuspended::class, ServiceTerminated::class]);
        $service = Service::factory()->create(['next_due_date' => '2026-11-01']);
        app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25'));

        $this->assertSame(['suspended' => 0, 'terminated' => 0], $this->runOn('2026-11-03'));
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        $this->assertSame(['suspended' => 1, 'terminated' => 0], $this->runOn('2026-11-04'));
        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
        Event::assertDispatched(ServiceSuspended::class);

        $this->assertSame(['suspended' => 0, 'terminated' => 0], $this->runOn('2026-11-05'));

        $this->assertSame(['suspended' => 0, 'terminated' => 1], $this->runOn('2026-12-01'));
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        Event::assertDispatched(ServiceTerminated::class);
    }

    public function test_null_settings_turn_off_automation(): void
    {
        config(['billing.suspend_after_days' => null, 'billing.terminate_after_days' => null]);
        $service = Service::factory()->create(['next_due_date' => '2026-11-01']);
        app(InvoiceGenerator::class)->generate(CarbonImmutable::parse('2026-10-25'));

        $this->assertSame(['suspended' => 0, 'terminated' => 0], $this->runOn('2027-06-01'));
        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
    }
}
