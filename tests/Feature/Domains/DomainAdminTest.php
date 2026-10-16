<?php

namespace Tests\Feature\Domains;

use App\Domains\DomainInfo;
use App\Domains\RegistrarRegistry;
use App\Enums\DomainStatus;
use App\Enums\StaffRole;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Tld;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DomainSetup;
use Tests\TestCase;

class DomainAdminTest extends TestCase
{
    use DomainSetup, RefreshDatabase;

    private const SETTINGS = '/admin/settings/domains';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeRegistrar();
        $this->client = $this->registrant();
        $this->actingAs(User::factory()->create());
    }

    public function test_tlds_are_added_with_a_price_grid(): void
    {
        $this->get(route('admin.tlds.create'))->assertOk()->assertSee('Fake Registrar');

        $this->post(route('admin.tlds.store'), [
            'extension' => '.CO.UK', 'registrar' => 'fake', 'active' => '1', 'id_protection' => '1',
            'prices' => ['USD' => [
                1 => ['register' => '8.99', 'transfer' => '7.50', 'renew' => '9.99', 'id_protection' => ''],
                2 => ['register' => '', 'transfer' => '', 'renew' => '', 'id_protection' => ''],
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $tld = Tld::sole();
        $this->assertSame('co.uk', $tld->extension);
        $this->assertTrue($tld->id_protection);
        $price = $tld->prices()->sole();
        $this->assertSame([899, 750, 999, 0], [$price->register, $price->transfer, $price->renew, $price->id_protection]);
        $this->get(route('admin.tlds.index'))->assertSee('.co.uk')->assertSee('$8.99');
    }

    public function test_tld_prices_must_be_amounts_and_extensions_unique(): void
    {
        $this->tld();

        $this->post(route('admin.tlds.store'), ['extension' => 'com'])->assertSessionHasErrors('extension');
        $this->post(route('admin.tlds.store'), ['extension' => 'net', 'prices' => ['USD' => [1 => ['register' => 'cheap']]]])
            ->assertSessionHasErrors('prices.USD.1.register');
        $this->assertSame(1, Tld::count());
    }

    public function test_a_tld_with_domains_cant_be_deleted(): void
    {
        $tld = $this->tld();
        Domain::factory()->create(['tld_id' => $tld->id]);

        $this->delete(route('admin.tlds.destroy', $tld))->assertSessionHasErrors(['tld' => 'Domains use .com. Untick Active to stop selling it instead.']);
        $this->assertModelExists($tld);
    }

    public function test_settings_keep_saved_secrets_and_test_the_connection(): void
    {
        $this->get(self::SETTINGS)->assertOk()->assertSee('Fake Registrar')->assertDontSee('secret-token');

        $this->put(self::SETTINGS, [
            'credentials' => ['fake' => ['token' => '', 'sandbox' => '1']],
            'nameservers' => ['NS1.Example.net.', 'ns2.example.net', ''],
            'invoice_days_before_expiry' => '21',
            'reminder_days' => '7, 30, 30, 1',
            'auto_renew_default' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['token' => 'secret-token', 'sandbox' => '1'], app(RegistrarRegistry::class)->credentials('fake'));
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], config('domains.nameservers'));
        $this->assertSame([30, 7, 1], config('domains.reminder_days'));
        $this->assertSame(21, (int) config('domains.invoice_days_before_expiry'));

        $this->post(route('admin.domain-settings.test', 'fake'))->assertSessionHas('status', 'Fake Registrar answered: the credentials work.');
        $this->registrar->refuse = 'Invalid API key';
        $this->post(route('admin.domain-settings.test', 'fake'))->assertSessionHasErrors(['registrar' => 'Invalid API key']);
    }

    public function test_bad_nameservers_are_refused(): void
    {
        $this->put(self::SETTINGS, ['nameservers' => ['not a host'], 'invoice_days_before_expiry' => '30'])
            ->assertSessionHasErrors(['nameservers' => "These aren't nameserver names: not a host."]);
    }

    public function test_staff_add_an_existing_domain_and_sync_it(): void
    {
        $this->tld();

        $this->post(route('admin.domains.store', $this->client), [
            'name' => 'Existing.com', 'status' => 'active', 'years' => '1', 'expiry_date' => '2027-05-01', 'auto_renew' => '1',
        ])->assertSessionHasNoErrors();

        $domain = Domain::sole();
        $this->assertSame('fake', $domain->registrar);
        $this->assertSame(1500, $domain->recurring_amount);
        $this->assertSame('2027-05-01', $domain->next_due_date->toDateString());
        $this->assertSame(0, $this->client->invoices()->count());

        $this->registrar->info = new DomainInfo(DomainInfo::ACTIVE, CarbonImmutable::parse('2028-05-01'));
        $this->post(route('admin.domains.action', [$domain, 'sync']))->assertSessionHas('status');
        $this->assertSame('2028-05-01', $domain->refresh()->expiry_date->toDateString());

        $this->get(route('admin.domains.index'))->assertSee('existing.com');
        $this->get(route('admin.domains.show', $domain))->assertOk()->assertSee('existing.com');
        $this->get(route('admin.clients.show', $this->client))->assertSee('existing.com');
    }

    public function test_staff_retry_a_failed_registration(): void
    {
        $domain = Domain::factory()->pending()->for($this->client)->create(['registrar' => 'fake', 'nameservers' => ['ns1.a.test', 'ns2.a.test'], 'registrar_status' => 'failed', 'registrar_error' => 'Timed out']);
        $this->get(route('admin.domains.index'))->assertSee('1 domain had a registrar request fail.');

        $this->post(route('admin.domains.action', [$domain, 'register']))->assertSessionHas('status', 'Register sent to the registrar.');

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertNull($domain->registrar_error);
    }

    public function test_domains_appear_in_the_admin_search(): void
    {
        Domain::factory()->for($this->client)->create(['name' => 'findme.com']);

        $this->get('/admin/search?q=findme')->assertOk()->assertSee('findme.com');
    }

    public function test_support_staff_see_domains_but_dont_change_them(): void
    {
        $domain = Domain::factory()->for($this->client)->create(['registrar' => 'fake']);
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));

        $this->get(route('admin.domains.show', $domain))->assertOk();
        $this->post(route('admin.domains.action', [$domain, 'renew']))->assertForbidden();
        $this->get(self::SETTINGS)->assertForbidden();
        $this->get(route('admin.tlds.index'))->assertForbidden();
        $this->assertSame([], $this->registrar->calls);
    }
}
