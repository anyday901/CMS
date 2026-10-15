<?php

namespace Tests\Feature\Admin;

use App\Console\Scheduler;
use App\Models\Activity;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/automation';

    private const CRON = '/admin/settings/cron';

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'run_time' => '02:30', 'invoice_days_before_due' => '14', 'apply_credit_automatically' => '0', 'reminder_days' => '14, 1, 7, 7',
            'late_fee_after_days' => '10', 'late_fee_type' => 'percent', 'late_fee_amount' => '5', 'suspend_after_days' => '5', 'terminate_after_days' => '', 'overage_due_days' => '3',
        ];
    }

    public function test_every_billing_run_day_is_saved_in_one_place_and_used(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->get('/admin/settings')->assertOk()->assertSee('Automation settings')->assertDontSee('name="late_fee_type"', false);
        $this->get(self::URL)->assertOk()->assertSee('Suspension and termination')->assertSee('value="00:05"', false);
        $this->put(self::URL, $this->form())->assertRedirect(self::URL)->assertSessionHasNoErrors();

        Settings::apply();
        $this->assertSame([14, '02:30', false, [1, 7, 14], '10', 'percent', '5.00', '5', null, '3'], [
            (int) config('billing.invoice_days_before_due'), config('billing.run_time'), config('billing.apply_credit_automatically'), config('billing.reminder_days'),
            (string) config('billing.late_fee_after_days'), config('billing.late_fee_type'), config('billing.late_fee_amount'),
            (string) config('billing.suspend_after_days'), config('billing.terminate_after_days'), (string) config('billing.overage_due_days'),
        ]);
        $this->assertTrue(Activity::where('description', 'Updated automation settings')->exists());

        // The billing run moves to the new time.
        $schedule = new Schedule;
        Scheduler::define($schedule);
        $this->assertSame('30 2 * * *', collect($schedule->events())->first(fn ($event) => ScheduledTaskRun::taskName($event) === 'billing:run')->expression);

        $this->get(self::URL)->assertSee('value="02:30"', false)->assertSee('value="1, 7, 14"', false);
    }

    public function test_bad_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->put(self::URL, $this->form(['reminder_days' => '1, soon']))->assertSessionHasErrors('reminder_days');
        $this->put(self::URL, $this->form(['run_time' => '25:00']))->assertSessionHasErrors('run_time');
        $this->put(self::URL, $this->form(['suspend_after_days' => '10', 'terminate_after_days' => '5']))->assertSessionHasErrors('terminate_after_days');
        $this->put(self::URL, $this->form(['invoice_days_before_due' => '']))->assertSessionHasErrors('invoice_days_before_due');
        $this->assertNull(config('billing.run_time'));
    }

    public function test_scheduled_task_runs_are_recorded_and_shown(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->get(self::CRON)->assertOk()->assertSee('The scheduler hasn&#039;t run yet.', false)->assertSee('php artisan schedule:run')->assertSee('billing:run');

        $schedule = new Schedule;
        Scheduler::define($schedule);
        [$billing, $usage] = collect($schedule->events())->take(2)->all();

        event(new ScheduledTaskStarting($billing));
        $billing->exitCode = 0;
        event(new ScheduledTaskFinished($billing, 1.2));

        $this->travel(1)->minutes();
        event(new ScheduledTaskStarting($usage));
        event(new ScheduledTaskFailed($usage, new RuntimeException('Server cp1 timed out')));
        event(new ScheduledTaskSkipped($billing));

        $this->assertSame([['billing:run', 'succeeded'], ['usage:collect', 'failed'], ['billing:run', 'skipped']],
            ScheduledTaskRun::orderBy('id')->get()->map(fn ($run) => [$run->task, $run->status])->all());

        $this->get(self::CRON)->assertOk()
            ->assertSee('The scheduler is running.')
            ->assertSeeInOrder(['Billing run', 'Succeeded', 'Read disk and bandwidth', 'Failed', 'Server cp1 timed out']);
    }

    public function test_a_task_that_exits_with_an_error_counts_as_failed(): void
    {
        $schedule = new Schedule;
        Scheduler::define($schedule);
        $billing = collect($schedule->events())->first();

        event(new ScheduledTaskStarting($billing));
        $billing->exitCode = 1;
        event(new ScheduledTaskFinished($billing, 0.4));

        $run = ScheduledTaskRun::sole();
        $this->assertSame([ScheduledTaskRun::FAILED, 1, 'Exited with code 1.'], [$run->status, $run->exit_code, $run->error]);
    }

    public function test_old_task_runs_are_pruned(): void
    {
        ScheduledTaskRun::create(['task' => 'billing:run', 'started_at' => now()->subDays(40), 'status' => 'succeeded']);
        ScheduledTaskRun::create(['task' => 'billing:run', 'started_at' => now()->subDay(), 'status' => 'succeeded']);

        $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class]])->assertSuccessful();

        $this->assertSame(1, ScheduledTaskRun::count());
    }
}
