<?php

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAdminTest extends TestCase
{
    use MakesApiKeys, RefreshDatabase;

    private const URL = '/admin/settings/api-keys';

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Alex Admin']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    public function test_a_new_key_is_shown_once_and_then_works(): void
    {
        $admin = $this->admin();
        $this->get(self::URL)->assertOk()->assertSee('No API keys yet.')->assertSee('Record payments on invoices');

        $this->post(self::URL, [
            'name' => 'Accounting sync',
            'abilities' => ['invoices:read', 'clients:read'],
            'allowed_ips' => "203.0.113.0/24\n127.0.0.1",
            'expires_on' => today()->addMonth()->toDateString(),
        ])->assertRedirect(self::URL)->assertSessionHasNoErrors();

        $key = ApiKey::sole();
        $plain = session('new_key')['key'];
        $this->assertSame(['clients:read', 'invoices:read'], $key->abilities);
        $this->assertSame(['203.0.113.0/24', '127.0.0.1'], $key->allowed_ips);
        $this->assertSame($admin->id, $key->user_id);
        $this->assertTrue($key->expires_at->isSameDay(today()->addMonth()));
        $this->assertSame("Created API key {$key->name}", Activity::latest('id')->value('description'));

        $this->get(self::URL)->assertOk()->assertSee($plain)->assertSee('won&#039;t be shown again', false);
        $this->get(self::URL)->assertOk()->assertDontSee($plain)->assertSee($key->prefix);

        $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Accounting sync');
    }

    public function test_keys_need_a_name_an_ability_and_valid_addresses(): void
    {
        $this->admin();

        $this->post(self::URL, ['name' => '', 'abilities' => []])->assertSessionHasErrors(['name', 'abilities']);
        $this->post(self::URL, ['name' => 'X', 'abilities' => ['everything']])->assertSessionHasErrors('abilities.0');
        $this->post(self::URL, ['name' => 'X', 'abilities' => ['clients:read'], 'allowed_ips' => 'not-an-ip'])->assertSessionHasErrors('allowed_ips');
        $this->post(self::URL, ['name' => 'X', 'abilities' => ['clients:read'], 'expires_on' => today()->toDateString()])->assertSessionHasErrors('expires_on');
        $this->assertSame(0, ApiKey::count());
    }

    public function test_keys_are_edited_revoked_and_deleted(): void
    {
        $this->admin();
        [$key, $plain] = $this->apiKey(['clients:read']);

        $this->get(self::URL."/{$key->id}/edit")->assertOk()->assertSee('Edit Test key');
        $this->put(self::URL."/{$key->id}", ['name' => 'Renamed', 'abilities' => ['tickets:read', 'tickets:write'], 'allowed_ips' => ''])
            ->assertRedirect(self::URL);
        $key->refresh();
        $this->assertSame(['Renamed', ['tickets:read', 'tickets:write'], null], [$key->name, $key->abilities, $key->allowed_ips]);
        $this->assertSame(hash('sha256', $plain), $key->secret_hash);

        $this->withToken($plain)->getJson('/api/v1/tickets')->assertOk();
        $this->post(self::URL."/{$key->id}/revoke")->assertRedirect(self::URL);
        $this->assertTrue($key->fresh()->isRevoked());
        $this->withToken($plain)->getJson('/api/v1/tickets')->assertUnauthorized();
        $this->get(self::URL)->assertSee('Revoked');

        $this->delete(self::URL."/{$key->id}")->assertRedirect(self::URL);
        $this->assertSame(0, ApiKey::count());
    }

    public function test_only_staff_who_manage_settings_reach_api_keys(): void
    {
        [$key] = $this->apiKey();
        $this->actingAs(User::factory()->create(['role' => 'billing']), 'web');

        $this->get(self::URL)->assertForbidden();
        $this->post(self::URL, ['name' => 'X', 'abilities' => ['clients:read']])->assertForbidden();
        $this->post(self::URL."/{$key->id}/revoke")->assertForbidden();
        $this->assertFalse($key->fresh()->isRevoked());
    }
}
