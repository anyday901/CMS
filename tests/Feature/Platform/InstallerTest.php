<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\User;
use App\System\EnvFile;
use App\System\Installer;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $env;

    protected function setUp(): void
    {
        parent::setUp();

        $this->env = sys_get_temp_dir().'/cms-installer-'.uniqid().'.env';
        file_put_contents($this->env, "APP_NAME=CMS\n# DB_HOST=127.0.0.1\n");
        $this->app->instance(EnvFile::class, new EnvFile($this->env));

        config(['installer.enabled' => true]);
        File::delete(storage_path('app/installed'));
        Installer::forget();
    }

    protected function tearDown(): void
    {
        File::delete([$this->env, storage_path('app/installed')]);
        Installer::forget();

        parent::tearDown();
    }

    public function test_every_page_goes_to_the_installer_until_there_is_a_staff_account(): void
    {
        $this->get('/admin/login')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertSee('Create your account')->assertSee('PHP 8.3 or newer');
        $this->get('/up')->assertOk();
    }

    public function test_the_account_step_creates_the_first_admin_and_closes_the_installer(): void
    {
        $this->post('/install/finish', ['company' => 'Example Hosting', 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertSee('ada@example.com');
        $this->assertDatabaseCount('users', 0);

        $this->post('/install/finish', ['company' => 'Example Hosting', 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a long password', 'password_confirmation' => 'a long password'])
            ->assertOk()->assertSee(url('/admin/login'));

        $user = User::sole();
        $this->assertSame(StaffRole::Admin, $user->role);
        $this->assertTrue(Hash::check('a long password', $user->password));
        $this->assertSame('Example Hosting', config('billing.company.name'));
        $this->assertStringContainsString('APP_NAME="Example Hosting"', file_get_contents($this->env));
        $this->assertFileExists(storage_path('app/installed'));

        $this->get('/install')->assertNotFound();
        $this->post('/install/finish', ['company' => 'Other', 'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a long password', 'password_confirmation' => 'a long password'])->assertNotFound();
        $this->get('/admin/login')->assertOk();
    }

    public function test_an_existing_install_with_staff_is_never_sent_to_the_installer(): void
    {
        User::factory()->create();

        $this->get('/admin/login')->assertOk();
        $this->get('/install')->assertNotFound();
        $this->assertFileExists(storage_path('app/installed'));
    }

    public function test_a_database_that_wont_connect_is_reported_and_the_old_settings_are_kept(): void
    {
        $this->post('/install/database', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'cms', 'username' => 'cms', 'password' => 'secret'])
            ->assertStatus(422)->assertSee('Couldn&#039;t connect to the database', false)->assertDontSee('secret');

        $this->assertSame('sqlite', config('database.default'));
        $this->assertStringNotContainsString('DB_CONNECTION', file_get_contents($this->env));
    }

    public function test_the_database_name_is_checked(): void
    {
        $this->post('/install/database', ['driver' => 'sqlite', 'database' => 'cms; rm -rf'])
            ->assertStatus(422)->assertSee('Use letters, digits, dots, dashes and underscores');
    }

    public function test_the_installer_can_be_turned_off(): void
    {
        config(['installer.enabled' => false]);

        $this->get('/install')->assertNotFound();
        $this->get('/admin/login')->assertOk();
    }

    public function test_env_values_are_replaced_quoted_and_added(): void
    {
        $env = new EnvFile($this->env);
        $env->set(['APP_NAME' => 'Ada\'s "Hosting"', 'DB_HOST' => 'db.example.com', 'APP_DEBUG' => false, 'DB_PASSWORD' => 'p$ss word']);

        $contents = file_get_contents($this->env);
        $this->assertStringNotContainsString('# DB_HOST', $contents);
        $this->assertSame(
            ['APP_NAME' => 'Ada\'s "Hosting"', 'DB_HOST' => 'db.example.com', 'APP_DEBUG' => 'false', 'DB_PASSWORD' => 'p$ss word'],
            Dotenv::parse($contents),
        );

        $this->expectException(\RuntimeException::class);
        $env->set(["BAD\nKEY" => 'x']);
    }
}
