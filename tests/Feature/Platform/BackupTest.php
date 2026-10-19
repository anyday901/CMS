<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\User;
use App\System\Backups\BackupManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/backups';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/cms-backups-'.uniqid();
        config(['backups.path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->path);

        parent::tearDown();
    }

    public function test_staff_back_up_download_and_delete(): void
    {
        $this->actingAs(User::factory()->create());
        Client::factory()->create(['first_name' => "O'Brien", 'notes' => "Line one\nLine two"]);

        $this->get(self::URL)->assertOk()->assertSee('No backups yet.');
        $this->post(self::URL)->assertRedirect(self::URL)->assertSessionHas('status', 'Database backed up.');

        $backup = app(BackupManager::class)->latest();
        $this->assertNotNull($backup);
        $this->assertMatchesRegularExpression('/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $backup['name']);
        $sql = gzdecode((string) file_get_contents($this->path.'/'.$backup['name']));
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertStringContainsString('CREATE TABLE "clients"', $sql);
            $this->assertStringContainsString("'O''Brien'", $sql);
            $this->assertStringContainsString("'Line one' || char(10) || 'Line two'", $sql);
        } else {
            $this->assertStringContainsString('CREATE TABLE `clients`', $sql);
            $this->assertStringContainsString("'O\\'Brien'", $sql);
            $this->assertStringContainsString("'Line one\\nLine two'", $sql);
        }

        $this->get(self::URL)->assertSee($backup['name']);
        $this->get(self::URL.'/'.$backup['name'])->assertOk()->assertDownload($backup['name']);
        $this->get(self::URL.'/..%2F.env')->assertNotFound();
        $this->get(self::URL.'/backup-2026-01-01-000000.sql.gz')->assertNotFound();

        $this->delete(self::URL.'/'.$backup['name'])->assertRedirect(self::URL);
        $this->assertNull(app(BackupManager::class)->latest());
    }

    public function test_a_backup_restores_the_database(): void
    {
        $client = Client::factory()->create(['first_name' => 'Before', 'notes' => "Two\nlines"]);
        $name = app(BackupManager::class)->create();

        $client->update(['first_name' => 'After']);
        Client::factory()->create();

        $this->artisan('backup:restore', ['file' => $name, '--force' => true])->assertSuccessful();

        $this->assertSame(1, Client::count());
        $this->assertSame('Before', Client::first()->first_name);
        $this->assertSame("Two\nlines", Client::first()->notes);
    }

    public function test_only_the_newest_backups_are_kept_and_copied_to_the_backup_disk(): void
    {
        Storage::fake('s3-backups');
        config(['backups.keep' => 2, 'backups.disk' => 's3-backups']);
        $backups = app(BackupManager::class);

        for ($made = 0; $made < 3; $made++) {
            $names[] = $backups->create();
            $this->travel(1)->seconds();
        }

        $this->assertSame([$names[2], $names[1]], $backups->all()->pluck('name')->all());
        Storage::disk('s3-backups')->assertExists('backups/'.$names[0]);
        Storage::disk('s3-backups')->assertExists('backups/'.$names[2]);
    }

    public function test_the_schedule_setting_decides_when_automatic_backups_run(): void
    {
        $this->actingAs(User::factory()->create());
        $this->put(self::URL.'/settings', ['schedule' => 'weekly', 'time' => '02:00', 'keep' => 7, 'disk' => ''])->assertSessionHasNoErrors();

        $this->travelTo(now()->next('Saturday'));
        $this->artisan('backup:run --scheduled')->expectsOutputToContain('No automatic backup today')->assertSuccessful();
        $this->travelTo(now()->next('Sunday'));
        $this->artisan('backup:run --scheduled')->expectsOutputToContain('Backed up the database')->assertSuccessful();

        config(['backups.schedule' => 'off']);
        $this->assertFalse(BackupManager::dueToday());
    }

    public function test_backups_need_the_system_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Billing]));

        $this->get(self::URL)->assertForbidden();
        $this->post(self::URL)->assertForbidden();
    }
}
