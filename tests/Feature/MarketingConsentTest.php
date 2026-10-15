<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\User;
use App\Privacy\ClientAnonymiser;
use App\Privacy\ClientDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MarketingConsentTest extends TestCase
{
    use RefreshDatabase;

    private const PREFERENCE = '/portal/account/marketing';

    public function test_clients_opt_in_and_out_in_the_portal_with_a_record_of_when(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'client');

        $this->get('/portal/account')->assertSee('Email me news and special offers');
        $this->put(self::PREFERENCE, ['marketing_emails' => '1'])->assertSessionHas('status');
        $client->refresh();
        $this->assertTrue($client->marketing_emails);
        $this->assertNotNull($client->marketing_consent_at);
        $this->assertSame(1, Activity::where('description', 'Agreed to marketing emails')->count());

        // Saving the same answer again isn't a new record.
        $this->put(self::PREFERENCE, ['marketing_emails' => '1']);
        $this->assertSame(1, Activity::where('description', 'Agreed to marketing emails')->count());

        $this->put(self::PREFERENCE, ['marketing_emails' => '0']);
        $this->assertFalse($client->fresh()->marketing_emails);
        $this->assertSame(1, Activity::where('description', 'Opted out of marketing emails')->count());
    }

    public function test_the_unsubscribe_link_works_without_logging_in(): void
    {
        $client = Client::factory()->create();
        $client->setMarketingConsent(true);
        $client->save();
        $url = $client->unsubscribeUrl();

        $this->get($url)->assertOk()->assertSee('Unsubscribe from news and offers?');
        $this->assertTrue($client->fresh()->marketing_emails);

        // Mail apps' one-click unsubscribe posts straight to the link.
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee("You're unsubscribed", false);
        $this->assertFalse($client->fresh()->marketing_emails);
        $this->get($url)->assertSee("You're unsubscribed", false);

        // A link for someone else, or a tampered one, does nothing.
        $other = Client::factory()->create();
        $this->post(str_replace("/unsubscribe/{$client->id}", "/unsubscribe/{$other->id}", $url))->assertForbidden();
        $this->get(URL::route('marketing.unsubscribe', $client))->assertForbidden();
    }

    public function test_new_clients_have_not_agreed_and_staff_record_it_when_asked(): void
    {
        $this->assertFalse(Client::factory()->create()->fresh()->marketing_emails);

        $this->actingAs(User::factory()->create());
        $kept = Client::factory()->create();
        $this->put("/admin/clients/{$kept->id}", [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => $kept->email, 'status' => 'active', 'marketing_emails' => '1',
        ])->assertRedirect();
        $this->assertTrue($kept->fresh()->marketing_emails);
        $this->assertNotNull(Activity::where('description', 'Agreed to marketing emails (set by staff)')->first());
    }

    public function test_staff_filter_and_export_clients_who_agreed(): void
    {
        $this->actingAs(User::factory()->create());
        $group = ClientGroup::create(['name' => 'VIP', 'colour' => '#dc2626']);
        $yes = Client::factory()->create(['first_name' => 'Ada', 'last_name' => 'Yes', 'company' => '=HYPERLINK("https://evil.example")', 'client_group_id' => $group->id]);
        $yes->setMarketingConsent(true);
        $yes->save();
        Client::factory()->create(['first_name' => 'Bea', 'last_name' => 'No']);

        $this->get('/admin/clients?marketing_emails=yes')->assertSee('Ada Yes')->assertDontSee('Bea No')->assertSee('Export to CSV');
        $this->get("/admin/clients/{$yes->id}")->assertSee('Marketing emails')->assertSee('Agreed');

        $csv = $this->get('/admin/clients/export?marketing_emails=yes')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('id,first_name,last_name', $lines[0]);
        $this->assertStringContainsString('Ada,Yes', $lines[1]);
        $this->assertStringContainsString('VIP,yes', $lines[1]);
        // A value a spreadsheet would run as a formula is written as text.
        $this->assertStringContainsString('"\'=HYPERLINK(""https://evil.example"")"', $lines[1]);
        $this->assertStringContainsString('/unsubscribe/'.$yes->id.'?signature=', $lines[1]);
    }

    public function test_consent_is_in_the_data_export_and_cleared_when_anonymised(): void
    {
        $client = Client::factory()->create(['status' => 'closed']);
        $client->setMarketingConsent(true);
        $client->save();

        $data = app(ClientDataExport::class)->build($client);
        $this->assertTrue($data['client']['marketing_emails']);
        $this->assertNotNull($data['client']['marketing_consent_at']);

        app(ClientAnonymiser::class)->anonymise($client);
        $this->assertFalse($client->fresh()->marketing_emails);
        $this->assertNull($client->fresh()->marketing_consent_at);
    }
}
