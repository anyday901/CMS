<?php

namespace Tests\Feature\Platform;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/system';

    private const RELEASES = 'api.github.com/repos/*/releases/latest';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([self::RELEASES => Http::response(['tag_name' => 'v9.0.0', 'name' => 'CMS 9', 'body' => 'New things', 'html_url' => 'https://github.com/anyday901/CMS/releases/v9.0.0', 'assets' => []])]);
        config(['backups.path' => sys_get_temp_dir().'/cms-health-'.uniqid()]);
    }

    public function test_the_health_page_lists_each_check(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::URL)->assertOk()
            ->assertSee('System health')
            ->assertSee('PHP version')
            ->assertSee(DB::connection()->getDriverName() === 'sqlite' ? 'Connected to SQLite' : 'Connected to ')
            ->assertSee('No automatic task has run yet')
            ->assertSee('The sync queue sends email')
            ->assertSee('No database backup yet')
            ->assertSee('Version 9.0.0 is out');
    }

    public function test_the_health_endpoint_answers_503_while_something_is_a_problem(): void
    {
        $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'problem']);

        // Once the scheduler runs, what's left here (sync queue, log mailer, no backup) are only warnings.
        ScheduledTaskRun::create(['task' => 'billing:run', 'started_at' => now(), 'status' => ScheduledTaskRun::SUCCEEDED]);
        $this->getJson('/health')->assertOk()->assertJson(['status' => 'warning'])->assertJsonMissingPath('checks');
    }

    public function test_the_health_token_shows_each_check(): void
    {
        config(['app.health_token' => 'secret-token']);

        $this->getJson('/health?token=wrong')->assertJsonMissingPath('checks');
        $this->getJson('/health?token=secret-token')->assertJsonPath('checks.database', 'ok')->assertJsonPath('checks.scheduler', 'problem');
    }

    public function test_a_check_that_throws_is_reported_as_a_problem(): void
    {
        config(['app.health_token' => 'secret']);
        Schema::drop('scheduled_task_runs');

        $this->getJson('/health?token=secret')->assertStatus(503)->assertJsonPath('checks.scheduler', 'problem');
        $this->actingAs(User::factory()->create())->get('/admin/settings/system')->assertOk()->assertSee('Couldn&#039;t check this', false);
    }

    public function test_debug_mode_in_production_is_a_problem(): void
    {
        $this->actingAs(User::factory()->create());
        config(['app.debug' => true, 'app.env' => 'production']);
        $this->app['env'] = 'production';

        $this->get(self::URL)->assertSee('APP_DEBUG is on');
    }
}
