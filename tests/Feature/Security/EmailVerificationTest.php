<?php

namespace Tests\Feature\Security;

use App\Mail\LoggedEmail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Product;
use App\Models\User;
use App\Security\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = '/portal';

    private const BANNER = 'Please confirm your email address';

    private function linkFrom(LoggedEmail $mail): ?string
    {
        preg_match('#href="([^"]*verify-email[^"]*)"#', $mail->render(), $m);

        return isset($m[1]) ? html_entity_decode($m[1]) : null;
    }

    public function test_signing_up_at_checkout_sends_a_link_that_verifies_the_email(): void
    {
        Mail::fake();
        $product = Product::factory()->create();
        $product->prices()->create(['billing_cycle' => 'monthly', 'currency' => 'USD', 'price' => 1000]);
        $this->post(route('cart.store', $product), ['billing_cycle' => 'monthly']);

        $this->post('/checkout', [
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com',
            'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
            'address1' => '1 Main St', 'city' => 'Austin', 'postcode' => '78701', 'country' => 'us',
        ])->assertRedirect();

        $client = Client::sole();
        $this->assertFalse($client->hasVerifiedEmail());
        $this->get(self::HOME)->assertSee(self::BANNER);

        $url = null;
        Mail::assertSent(LoggedEmail::class, function (LoggedEmail $mail) use (&$url) {
            $url = $this->linkFrom($mail) ?? $url;

            return $mail->hasTo('ada@example.com') && $url !== null;
        });
        $this->assertStringNotContainsString('signature', EmailLog::where('template', 'email_verification')->sole()->body);

        // The link works signed out too, as on another device.
        auth('client')->logout();
        $this->get($url)->assertRedirect('/portal/login')->assertSessionHas('status');
        $this->assertTrue($client->fresh()->hasVerifiedEmail());

        // A link with its signature changed doesn't.
        $client->fresh()->forceFill(['email_verified_at' => null])->save();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->assertFalse($client->fresh()->hasVerifiedEmail());
    }

    public function test_a_new_email_address_has_to_be_verified_again(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $oldLink = EmailVerification::url($client);
        $this->actingAs($client, 'client');
        $this->get(self::HOME)->assertDontSee(self::BANNER);

        $this->put('/portal/account', ['first_name' => $client->first_name, 'last_name' => $client->last_name, 'email' => 'new@example.com'])
            ->assertSessionHas('status', "Your details are saved. We've sent a link to new@example.com to confirm it's yours.");
        $this->assertFalse($client->fresh()->hasVerifiedEmail());
        $this->get(self::HOME)->assertSee(self::BANNER);
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasTo('new@example.com'));

        // A link sent to the old address doesn't verify the new one.
        $this->get($oldLink)->assertSessionHasErrors('email');
        $this->assertFalse($client->fresh()->hasVerifiedEmail());

        $this->post('/portal/account/verify-email')->assertSessionHas('status');
        Mail::assertSentCount(2);
    }

    public function test_staff_see_whether_the_email_is_verified_and_can_mark_it(): void
    {
        Mail::fake();
        $client = Client::factory()->unverified()->create();
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}")->assertSee('not verified')->assertSee('Email verification link');
        $this->post("/admin/clients/{$client->id}/verification-link")->assertSessionHas('status');
        $this->assertSame('email_verification', EmailLog::sole()->template);

        $this->post("/admin/clients/{$client->id}/verify-email")->assertRedirect();
        $this->assertTrue($client->fresh()->hasVerifiedEmail());

        // Staff changing the address makes it unverified again.
        $this->put("/admin/clients/{$client->id}", ['first_name' => 'A', 'last_name' => 'B', 'email' => 'changed@example.com', 'status' => 'active'])->assertRedirect();
        $this->assertFalse($client->fresh()->hasVerifiedEmail());
    }
}
