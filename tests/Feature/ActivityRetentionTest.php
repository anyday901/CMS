<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_keeps_everything_until_a_retention_is_set(): void
    {
        $old = Activity::record('Old entry');
        $old->forceFill(['created_at' => now()->subDays(100)])->save();
        Activity::record('New entry');

        $this->artisan('activity:prune')->expectsOutputToContain('retention is off')->assertSuccessful();
        $this->assertSame(2, Activity::count());

        $this->actingAs(User::factory()->create());
        $this->put('/admin/settings', [
            'late_fee_after_days' => '', 'late_fee_type' => 'fixed', 'late_fee_amount' => '', 'tax_inclusive' => '0',
            'attach_invoice_pdf' => '1', 'activity_retention_days' => '90',
        ])->assertSessionHasNoErrors();
        $this->assertSame('90', (string) Setting::find('activity.retention_days')->value);

        $this->artisan('activity:prune')->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertTrue(Activity::where('description', 'New entry')->exists());
    }
}
