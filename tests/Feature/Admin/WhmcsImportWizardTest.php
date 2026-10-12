<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsImportWizardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/whmcs-import';

    private const STEP_2 = self::URL.'?step=2';

    private const STEP_3 = self::URL.'?step=3';

    private const CONNECT = self::URL.'/connect';

    private const IMPORT = self::URL.'/import';

    private const CONFIRMED = ['confirm' => '1'];

    /** Connect with the WHMCS_DB_* connection, which the tests point at a WHMCS database in memory. */
    private const FROM_ENV = ['source' => 'env'];

    /** The error bag key the walkthrough reports import problems under. */
    private const ERROR = 'whmcs';

    protected function setUp(): void
    {
        parent::setUp();

        WhmcsDatabase::seed(WhmcsDatabase::create());
        $this->actingAs(User::factory()->create(), 'web');
    }

    protected function tearDown(): void
    {
        foreach (['dry_run', 'import'] as $run) {
            $path = session("whmcs_import_wizard.{$run}.path");

            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_settings_links_to_the_walkthrough(): void
    {
        $this->get('/admin/settings')->assertOk()->assertSee('Import from WHMCS')->assertSee(self::URL);
    }

    public function test_staff_walk_through_connecting_a_dry_run_and_the_import(): void
    {
        $this->get(self::URL)->assertOk()
            ->assertSee('Step 1 of 5')
            ->assertSee('This install is empty.')
            ->assertSee('Continue');

        $this->get(self::STEP_3)->assertOk()->assertSee('Step 2 of 5');
        $this->post(self::CONNECT, self::FROM_ENV)->assertRedirect(self::STEP_3);

        $this->get(self::STEP_2)->assertOk()->assertSee('Connected. This WHMCS database has:');
        $this->get(self::STEP_3)->assertOk()->assertSee('Start the dry run');

        $this->post(self::URL.'/dry-run')->assertRedirect(self::STEP_3);
        $this->assertSame(0, Client::count());
        $this->get(self::STEP_3)->assertOk()
            ->assertSee('Every total matches WHMCS.')
            ->assertSee('Ticket attachments: 1 note')
            ->assertSee('Continue to the import');
        $this->get(self::URL.'/report/dry_run')->assertOk()->assertDownload();

        $this->get(self::URL.'?step=4')->assertOk()->assertSee('Import now');
        $this->post(self::IMPORT)->assertSessionHasErrors('confirm');
        $this->assertSame(0, Client::count());

        $this->post(self::IMPORT, self::CONFIRMED)->assertRedirect(self::URL.'?step=5');
        $this->assertSame(1, Client::count());
        $this->assertSame(2, Invoice::count());
        $this->assertNotNull(Setting::find(WhmcsImporter::SETTING));
        $this->assertSame('Imported from WHMCS', Activity::latest('id')->value('description'));

        $this->get(self::STEP_2)->assertOk()
            ->assertSee('Step 5 of 5')
            ->assertSee('What to do next');
        $this->get(self::URL.'/report/import')->assertOk()->assertDownload();
    }

    public function test_an_install_with_data_cannot_go_past_the_first_step(): void
    {
        Client::factory()->create();

        $this->get(self::STEP_3)->assertOk()
            ->assertSee('Step 1 of 5')
            ->assertSee('This install already has clients.')
            ->assertDontSee('Start the dry run');
    }

    public function test_a_database_that_is_not_whmcs_is_refused(): void
    {
        DB::connection(WhmcsDatabase::CONNECTION)->getSchemaBuilder()->drop('tblclients');

        $this->from(self::STEP_2)->post(self::CONNECT, self::FROM_ENV)
            ->assertRedirect(self::STEP_2)
            ->assertSessionHasErrors(self::ERROR);
        $this->assertNull(session('whmcs_import_wizard'));
    }

    public function test_typed_connection_details_are_checked_and_the_password_is_not_kept_in_old_input(): void
    {
        $this->post(self::CONNECT, ['source' => 'form'])->assertSessionHasErrors(['host', 'database', 'username']);

        $this->post(self::CONNECT, [
            'source' => 'form', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1',
            'database' => 'whmcs', 'username' => 'cms', 'password' => 'secret',
        ])->assertSessionHasErrors(self::ERROR);

        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame('127.0.0.1', session()->getOldInput('host'));
        $this->assertNull(session('whmcs_import_wizard'));
    }

    public function test_a_missing_attachments_folder_is_refused(): void
    {
        $this->post(self::CONNECT, [...self::FROM_ENV, 'attachments' => '/no/such/folder'])
            ->assertSessionHasErrors([self::ERROR => 'No folder at /no/such/folder on this server.']);
    }

    public function test_the_import_needs_a_dry_run_and_staff_who_manage_settings(): void
    {
        $this->post(self::IMPORT, self::CONFIRMED)->assertSessionHasErrors(self::ERROR);
        $this->get(self::URL.'/report/dry_run')->assertNotFound();

        $this->post(self::CONNECT, self::FROM_ENV);
        $this->post(self::IMPORT, self::CONFIRMED)->assertSessionHasErrors([self::ERROR => 'Run the dry run before importing.']);
        $this->assertSame(0, Client::count());

        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]), 'web');
        $this->get(self::URL)->assertForbidden();
        $this->post(self::CONNECT, self::FROM_ENV)->assertForbidden();
    }
}
