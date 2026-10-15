<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use MakesApiKeys, RefreshDatabase;

    private const ME = '/api/v1/me';

    public function test_a_working_key_sees_its_name_and_abilities(): void
    {
        $key = $this->withApiKey(['clients:read', 'tickets:read']);

        $this->getJson(self::ME)->assertOk()
            ->assertJsonPath('data.name', 'Test key')
            ->assertJsonPath('data.prefix', $key->prefix)
            ->assertJsonPath('data.abilities', ['clients:read', 'tickets:read'])
            ->assertHeader('X-RateLimit-Limit', AuthenticateApiKey::PER_MINUTE);
    }

    public function test_only_a_hash_of_the_key_is_stored(): void
    {
        [$key, $plain] = $this->apiKey();

        $this->assertMatchesRegularExpression('/^cms_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $plain);
        $this->assertStringStartsWith($key->prefix.'_', $plain);
        $this->assertSame(hash('sha256', $plain), $key->fresh()->secret_hash);
        $this->assertStringNotContainsString(substr($plain, 13), json_encode(ApiKey::first()->getAttributes()));
    }

    public function test_requests_without_a_working_key_are_refused(): void
    {
        [, $plain] = $this->apiKey();

        $this->getJson(self::ME)->assertUnauthorized()->assertJsonStructure(['message']);
        $this->withToken('not-a-key')->getJson(self::ME)->assertUnauthorized();
        // The right prefix with the wrong secret.
        $this->withToken(substr($plain, 0, 13).str_repeat('x', 40))->getJson(self::ME)->assertUnauthorized();
        $this->withToken($plain)->getJson(self::ME)->assertOk();
    }

    public function test_revoked_and_expired_keys_are_refused(): void
    {
        [$revoked, $revokedPlain] = $this->apiKey();
        $revoked->forceFill(['revoked_at' => now()])->save();
        [, $expiredPlain] = $this->apiKey(attributes: ['expires_at' => now()->subMinute()]);
        [, $laterPlain] = $this->apiKey(attributes: ['expires_at' => now()->addDay()]);

        $this->withToken($revokedPlain)->getJson(self::ME)->assertUnauthorized()->assertJsonPath('message', 'This API key has been revoked.');
        $this->withToken($expiredPlain)->getJson(self::ME)->assertUnauthorized()->assertJsonPath('message', 'This API key has expired.');
        $this->withToken($laterPlain)->getJson(self::ME)->assertOk();
    }

    public function test_keys_with_an_ip_allowlist_only_work_from_those_addresses(): void
    {
        [, $plain] = $this->apiKey(attributes: ['allowed_ips' => ['203.0.113.0/24', '198.51.100.7']]);

        $this->withToken($plain)->getJson(self::ME)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])->withToken($plain)->getJson(self::ME)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->withToken($plain)->getJson(self::ME)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])->withToken($plain)->getJson(self::ME)->assertForbidden();
    }

    public function test_keys_only_do_what_their_abilities_allow(): void
    {
        $this->withApiKey(['clients:read']);
        $client = Client::factory()->create();

        $this->getJson("/api/v1/clients/{$client->id}")->assertOk();
        $this->getJson('/api/v1/services')->assertForbidden()->assertJsonPath('message', "This API key doesn't have the services:read ability.");
        $this->postJson('/api/v1/clients', [])->assertForbidden();
        $this->patchJson("/api/v1/clients/{$client->id}", ['first_name' => 'Changed'])->assertForbidden();
        // Refused before the lookup, so a key can't find out which records exist.
        $this->getJson('/api/v1/services/999')->assertForbidden();
        $this->assertNotSame('Changed', $client->fresh()->first_name);
    }

    public function test_last_use_is_recorded_at_most_once_a_minute(): void
    {
        $key = $this->withApiKey();
        $this->travelTo(now()->startOfMinute());

        $this->getJson(self::ME)->assertOk();
        $first = $key->fresh()->last_used_at;
        $this->assertNotNull($first);
        $this->assertSame('127.0.0.1', $key->fresh()->last_used_ip);

        $this->travel(30)->seconds();
        $this->getJson(self::ME)->assertOk();
        $this->assertTrue($key->fresh()->last_used_at->equalTo($first));

        $this->travel(31)->seconds();
        $this->getJson(self::ME)->assertOk();
        $this->assertTrue($key->fresh()->last_used_at->greaterThan($first));
    }

    public function test_each_key_has_a_rate_limit(): void
    {
        $this->withApiKey();

        for ($i = 0; $i < AuthenticateApiKey::PER_MINUTE; $i++) {
            $this->getJson(self::ME)->assertOk();
        }

        $this->getJson(self::ME)->assertStatus(429)->assertHeader('Retry-After');

        // Another key has its own limit.
        $this->withApiKey();
        $this->getJson(self::ME)->assertOk();
    }

    public function test_changes_made_through_the_api_are_logged_as_the_key(): void
    {
        $key = $this->withApiKey(attributes: ['name' => 'Billing sync']);
        $service = Service::factory()->create();

        $this->postJson("/api/v1/services/{$service->id}/suspend")->assertOk();

        $entry = Activity::where('subject_type', $service->getMorphClass())->where('subject_id', $service->id)->latest('id')->firstOrFail();
        $this->assertSame(['api', $key->id, 'API key: Billing sync'], [$entry->actor_type, $entry->actor_id, $entry->actor_name]);
        $this->assertSame($service->client_id, $entry->client_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }
}
