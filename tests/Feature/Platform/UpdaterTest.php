<?php

namespace Tests\Feature\Platform;

use App\Jobs\RunUpdate;
use App\Models\Activity;
use App\Models\User;
use App\System\Backups\BackupManager;
use App\System\UpdateChecker;
use App\System\Updater;
use App\System\UpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/updates';

    private const RELEASES = 'api.github.com/repos/*/releases/latest';

    private const ZIP_URL = 'https://github.com/anyday901/CMS/releases/download/v9.1.0/cms.zip';

    private const SUM_URL = 'https://github.com/anyday901/CMS/releases/download/v9.1.0/cms.zip.sha256';

    private const APP_FILE = '/app/Thing.php';

    private const VERSION_FILE = '/VERSION';

    private const AUTOLOAD = '/vendor/autoload.php';

    private const OLD_CODE = 'old code';

    private const REMOVED = '/app/Console/Commands/Removed.php';

    private const NEW_VERSION = '9.1.0';

    private string $root;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->root = sys_get_temp_dir().'/cms-install-'.uniqid();
        config(['backups.path' => $this->root.'-backups']);

        // The install being updated.
        $this->write('/artisan', 'old artisan');
        $this->write(self::VERSION_FILE, '9.0.0');
        $this->write(self::AUTOLOAD, 'old vendor');
        $this->write('/vendor/old-package/file.php', 'stale');
        $this->write(self::APP_FILE, self::OLD_CODE);
        $this->write('/.env', 'APP_KEY=keep-me');
        $this->write('/storage/app/upload.txt', 'client upload');
        $this->write('/themes/overrides/views/portal/dashboard.blade.php', 'my override');
        $this->write(self::REMOVED, 'a command the new release dropped');
        $this->write('/public/favicon-custom.png', 'the site\'s own file');

        $this->zip = $this->release(self::NEW_VERSION);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->root.'-backups');
        File::deleteDirectory(storage_path('app/updates'));

        parent::tearDown();
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->root.$path));
        file_put_contents($this->root.$path, $contents);
    }

    /** A release zip, its files under one folder as GitHub's would be. */
    private function release(string $version, ?string $packagedVersion = null): string
    {
        $path = $this->root.'-release.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ([
            'cms/artisan' => 'new artisan',
            'cms/VERSION' => $packagedVersion ?? $version,
            'cms/vendor/autoload.php' => 'new vendor',
            'cms'.self::APP_FILE => 'new code',
            'cms/app/Added.php' => 'added',
            'cms/.env' => 'APP_KEY=from-release',
            'cms/storage/app/upload.txt' => 'release storage',
        ] as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function fakeRelease(?string $checksum = null): array
    {
        Http::fake([
            self::RELEASES => Http::response([
                'tag_name' => 'v9.1.0', 'name' => 'CMS 9.1', 'body' => '**Faster** invoices', 'html_url' => 'https://github.com/anyday901/CMS/releases/tag/v9.1.0',
                'published_at' => '2026-10-01T00:00:00Z',
                'assets' => [
                    ['name' => 'cms.zip', 'browser_download_url' => self::ZIP_URL],
                    ['name' => 'cms.zip.sha256', 'browser_download_url' => self::SUM_URL],
                ],
            ]),
            self::SUM_URL => Http::response(($checksum ?? hash_file('sha256', $this->zip)).'  cms.zip'),
            self::ZIP_URL => Http::response((string) file_get_contents($this->zip)),
        ]);

        return app(UpdateChecker::class)->latest(fresh: true);
    }

    private function updater(?BackupManager $backups = null): Updater
    {
        return new Updater($backups ?? app(BackupManager::class), app(UpdateStatus::class), $this->root);
    }

    public function test_an_update_replaces_the_code_and_keeps_settings_uploads_and_overrides(): void
    {
        Process::fake();
        $release = $this->fakeRelease();
        $this->assertSame(self::NEW_VERSION, $release['version']);

        $this->updater()->update($release);

        $this->assertSame('new code', file_get_contents($this->root.self::APP_FILE));
        $this->assertSame('added', file_get_contents($this->root.'/app/Added.php'));
        $this->assertSame(self::NEW_VERSION, file_get_contents($this->root.self::VERSION_FILE));
        $this->assertSame('new vendor', file_get_contents($this->root.self::AUTOLOAD));
        $this->assertFileDoesNotExist($this->root.'/vendor/old-package/file.php', 'vendor is swapped whole.');
        $this->assertSame('APP_KEY=keep-me', file_get_contents($this->root.'/.env'));
        $this->assertSame('client upload', file_get_contents($this->root.'/storage/app/upload.txt'));
        $this->assertSame('my override', file_get_contents($this->root.'/themes/overrides/views/portal/dashboard.blade.php'));
        $this->assertCount(0, glob($this->root.'/vendor.old-*'));
        $this->assertFileDoesNotExist($this->root.self::REMOVED, 'CMS\'s own files the release dropped are deleted.');
        $this->assertFileExists($this->root.'/public/favicon-custom.png');

        Process::assertRan(fn (PendingProcess $process) => in_array('migrate', (array) $process->command, true) && $process->path === $this->root);
        Process::assertRan(fn (PendingProcess $process) => in_array('down', (array) $process->command, true));
        Process::assertRan(fn (PendingProcess $process) => in_array('up', (array) $process->command, true));
        $this->assertNotNull(app(BackupManager::class)->latest(), 'The database is backed up first.');
        $this->assertSame('done', app(UpdateStatus::class)->read()['state']);
        $this->assertTrue(Activity::where('description', 'like', 'Updated CMS from % to 9.1.0')->exists());
    }

    public function test_a_download_that_does_not_match_its_checksum_changes_nothing(): void
    {
        Process::fake();
        $release = $this->fakeRelease(str_repeat('a', 64));

        $this->expectsProblemRuntime(fn () => $this->updater()->update($release), 'doesn\'t match its checksum');

        $this->assertSame(self::OLD_CODE, file_get_contents($this->root.self::APP_FILE));
        Process::assertNothingRan();
        $this->assertSame('failed', app(UpdateStatus::class)->read()['state']);
    }

    public function test_a_failed_migration_puts_the_old_files_back(): void
    {
        Process::fake([
            '*migrate*' => Process::result('', 'SQLSTATE: table exists', 1),
            '*' => Process::result(),
        ]);
        $release = $this->fakeRelease();

        $this->expectsProblemRuntime(fn () => $this->updater()->update($release), 'php artisan migrate failed');

        $this->assertSame(self::OLD_CODE, file_get_contents($this->root.self::APP_FILE));
        $this->assertSame('old vendor', file_get_contents($this->root.self::AUTOLOAD));
        $this->assertSame('9.0.0', file_get_contents($this->root.self::VERSION_FILE));
        $this->assertFileDoesNotExist($this->root.'/app/Added.php', 'Files the release added are removed again.');
        $this->assertFileExists($this->root.self::REMOVED);
        Process::assertRan(fn (PendingProcess $process) => in_array('up', (array) $process->command, true));
    }

    public function test_a_failure_after_migrating_puts_the_database_back_too(): void
    {
        Process::fake([
            '*optimize:clear*' => Process::result('', 'Permission denied', 1),
            '*' => Process::result(),
        ]);
        $release = $this->fakeRelease();
        $backups = Mockery::mock(app(BackupManager::class));
        $backups->shouldReceive('restore')->once()->andReturn(0);

        $this->expectsProblemRuntime(fn () => $this->updater($backups)->update($release), 'php artisan optimize:clear failed');

        $this->assertSame(self::OLD_CODE, file_get_contents($this->root.self::APP_FILE));
    }

    public function test_the_update_job_says_so_when_github_cant_be_reached(): void
    {
        Http::fake([self::RELEASES => Http::response('', 502)]);
        app(UpdateStatus::class)->start(self::NEW_VERSION);

        RunUpdate::dispatchSync();

        $this->assertSame('failed', app(UpdateStatus::class)->read()['state']);
        $this->assertFalse(app(UpdateStatus::class)->running());
    }

    public function test_a_download_of_another_version_is_refused(): void
    {
        Process::fake();
        $this->zip = $this->release(self::NEW_VERSION, '9.0.5');
        $release = $this->fakeRelease();

        $this->expectsProblemRuntime(fn () => $this->updater()->update($release), 'says it is version 9.0.5');
        $this->assertSame(self::OLD_CODE, file_get_contents($this->root.self::APP_FILE));
    }

    public function test_git_checkouts_update_with_git(): void
    {
        File::ensureDirectoryExists($this->root.'/.git');

        $this->assertStringContainsString('git checkout', $this->updater()->blocker());
    }

    public function test_the_updates_page_shows_the_new_release_and_its_notes(): void
    {
        $this->fakeRelease();
        $this->actingAs(User::factory()->create());

        $this->get(self::URL)->assertOk()
            ->assertSee('9.1.0</strong> is available', false)
            ->assertSee('<strong>Faster</strong> invoices', false)
            // This copy runs from git, so it shows the git steps rather than the button.
            ->assertSee('git checkout');
        $this->post(self::URL)->assertSessionHasErrors('update');
    }

    public function test_a_tag_that_is_not_a_version_is_ignored(): void
    {
        Http::fake([self::RELEASES => Http::response(['tag_name' => "v1.0.0');alert(1);//", 'assets' => []])]);

        $this->assertNull(app(UpdateChecker::class)->latest(fresh: true));
    }

    private function expectsProblemRuntime(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected: {$message}");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
