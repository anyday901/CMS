<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Activity;
use App\Models\User;
use App\System\Logs\LogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/logs';

    private const LOG = 'laravel.log';

    private const SHOW = self::URL.'/'.self::LOG;

    private const DOWNLOAD = '/download';

    private const PHP_URL = '/admin/settings/php';

    private const FIRST = 'First thing';

    private const PAYMENT = 'Payment failed';

    private const DISK = 'Disk nearly full';

    private const INFO = 'INFO';

    private const ERROR = 'ERROR';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/cms-logs-'.uniqid();
        File::ensureDirectoryExists($this->path);
        config(['logging.viewer.path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_staff_list_the_log_files_and_see_the_other_logs(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::URL)->assertOk()->assertSee('No log files yet.')
            ->assertSee(route('admin.activity.index'))
            ->assertSee(route('admin.emails.index'))
            ->assertSee(route('admin.gateway-log.index'));

        File::put($this->path.'/'.self::LOG, $this->entry(self::ERROR, 'Something broke'));
        File::put($this->path.'/notes.txt', 'Not a log');

        $this->get(self::URL)->assertOk()
            ->assertSee(self::LOG)
            ->assertSee(route('admin.system.logs.download', self::LOG))
            ->assertDontSee('notes.txt');
        $this->get('/admin/settings')->assertSee(route('admin.system.logs.index'))->assertSee(route('admin.system.php'));
    }

    public function test_a_log_shows_newest_first_with_a_level_filter_search_and_pages(): void
    {
        $this->actingAs(User::factory()->create());
        File::put($this->path.'/'.self::LOG, implode('', [
            $this->entry(self::INFO, self::FIRST, '2026-10-01 09:00:00'),
            $this->entry(self::ERROR, self::PAYMENT, '2026-10-02 09:00:00')."#0 /app/Billing.php(12): charge()\n#1 {main}\n",
            $this->entry('WARNING', self::DISK, '2026-10-03 09:00:00'),
        ]));

        $this->get(self::SHOW)->assertOk()
            ->assertSeeInOrder([self::DISK, self::PAYMENT, self::FIRST])
            ->assertSee('#0 /app/Billing.php(12): charge()')
            ->assertSee('Error (1)');

        $this->get(self::SHOW.'?level=error')->assertOk()->assertSee(self::PAYMENT)->assertDontSee(self::DISK)->assertDontSee(self::FIRST);
        $this->get(self::SHOW.'?q=billing.php')->assertOk()->assertSee(self::PAYMENT)->assertDontSee(self::DISK);
        $this->get(self::SHOW.'?q=nothing+like+this')->assertOk()->assertSee('No entries that match.');
        $this->get(self::SHOW.'?level=bogus')->assertOk()->assertSee(self::FIRST);

        File::put($this->path.'/'.self::LOG, implode('', array_map(fn (int $i) => $this->entry(self::INFO, "Entry number {$i}."), range(1, 60))));
        $this->get(self::SHOW)->assertOk()->assertSee('Entry number 60.')->assertSee('Entry number 11.')->assertDontSee('Entry number 10.');
        $this->get(self::SHOW.'?page=2')->assertOk()->assertSee('Entry number 10.')->assertSee('Entry number 1.')->assertDontSee('Entry number 11.');
    }

    public function test_only_files_in_the_log_folder_can_be_read_or_downloaded(): void
    {
        $this->actingAs(User::factory()->create());
        File::put($this->path.'/'.self::LOG, $this->entry(self::INFO, 'Hello'));
        File::put($this->path.'/secret.txt', 'APP_KEY=base64:secret');
        $outside = dirname($this->path).'/outside-'.basename($this->path).'.log';
        File::put($outside, 'outside');
        symlink($outside, $this->path.'/linked.log');

        foreach (['..%2F.env', 'secret.txt', 'missing.log', '.hidden.log', 'linked.log'] as $name) {
            $this->get(self::URL.'/'.$name)->assertNotFound();
            $this->get(self::URL.'/'.$name.self::DOWNLOAD)->assertNotFound();
        }

        $this->get(self::SHOW.self::DOWNLOAD)->assertOk()->assertDownload(self::LOG);
        $this->assertTrue(Activity::where('description', 'Downloaded a log file')->exists());

        File::delete($outside);
    }

    public function test_only_the_end_of_a_big_log_is_read(): void
    {
        config(['logging.viewer.max_bytes' => 2048]);
        File::put($this->path.'/'.self::LOG, $this->entry(self::INFO, 'Oldest entry').str_repeat($this->entry('DEBUG', str_repeat('x', 80)), 60).$this->entry(self::ERROR, 'Newest entry'));

        $read = app(LogReader::class)->read(self::LOG);

        $this->assertTrue($read['truncated']);
        $this->assertSame('Newest entry', $read['entries'][0]['message']);
        $this->assertSame('error', $read['entries'][0]['level']);
        $this->assertNotContains('Oldest entry', array_column($read['entries'], 'message'));
        $this->assertThrows(fn () => app(LogReader::class)->read('../.env'), InvalidArgumentException::class);

        $this->actingAs(User::factory()->create());
        $this->get(self::SHOW)->assertOk()->assertSee('only its last 2 KB is shown');
    }

    public function test_the_php_info_page_shows_a_summary_and_phpinfo_without_the_environment(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(self::PHP_URL)->assertOk()
            ->assertSee(PHP_VERSION)
            ->assertSee('memory_limit')
            ->assertSee('pdo_sqlite')
            ->assertSee('phpinfo()')
            ->assertDontSee((string) config('app.key'));
    }

    public function test_logs_and_php_info_need_the_system_permission_and_are_off_in_the_demo(): void
    {
        File::put($this->path.'/'.self::LOG, $this->entry(self::INFO, 'Hello'));
        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]));

        foreach ([self::URL, self::SHOW, self::SHOW.self::DOWNLOAD, self::PHP_URL] as $url) {
            $this->get($url)->assertForbidden();
        }

        config(['demo.enabled' => true]);
        $this->actingAs(User::factory()->create());
        $this->get(self::URL)->assertOk();
        $this->get(self::SHOW)->assertSessionHasErrors('demo');
        $this->get(self::PHP_URL)->assertSessionHasErrors('demo');
    }

    private function entry(string $level, string $message, string $time = '2026-10-18 12:00:00'): string
    {
        return "[{$time}] testing.{$level}: {$message}\n";
    }
}
