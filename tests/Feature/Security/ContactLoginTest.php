<?php

namespace Tests\Feature\Security;

use App\Mail\LoggedEmail;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Department;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\LoginAttempt;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactLoginTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = '/portal';

    private const CONTACTS = '/portal/account/contacts';

    private const ACCOUNT = '/portal/account';

    private const SUPPORT = '/portal/support';

    private const PASSWORD = 'contact-password-1';

    private const BOOKKEEPER = 'books@example.com';

    private const LOGIN = '/portal/login';

    private function contact(Client $client, array $permissions, array $attributes = []): ClientContact
    {
        $contact = $client->contacts()->create($attributes + [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER,
            'portal_access' => true, 'permissions' => $permissions,
        ]);
        $contact->forceFill(['password' => self::PASSWORD])->save();

        return $contact;
    }

    private function logInAs(ClientContact $contact): void
    {
        $this->post(self::LOGIN, ['email' => $contact->email, 'password' => self::PASSWORD, 'remember' => '1'])->assertRedirect(self::HOME);
    }

    public function test_the_account_holder_invites_a_contact_who_chooses_a_password(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['company' => 'Acme Ltd']);
        $this->actingAs($client, 'client');

        $this->get('/portal/account/contacts/create')->assertOk()->assertSee('Can sign in to the portal');
        $this->post(self::CONTACTS, [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER,
            'portal_access' => '1', 'permissions' => ['billing', 'support', 'bogus'],
        ])->assertSessionHasErrors('permissions.2');

        $this->post(self::CONTACTS, [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER,
            'portal_access' => '1', 'permissions' => ['billing', 'support'],
        ])->assertRedirect(self::ACCOUNT);

        $contact = ClientContact::sole();
        $this->assertSame([true, ['billing', 'support']], [$contact->portal_access, $contact->permissions]);

        $token = null;
        Mail::assertSent(LoggedEmail::class, function (LoggedEmail $mail) use (&$token) {
            $html = $mail->render();
            preg_match('#reset-password/([A-Za-z0-9]+)#', $html, $m);
            $token = $m[1] ?? null;

            return $mail->hasTo(self::BOOKKEEPER) && str_contains($html, 'Acme Ltd has given you a login') && str_contains($html, 'see and pay invoices and open support tickets');
        });
        $this->assertSame('contact_invite', EmailLog::sole()->template);
        $this->assertStringNotContainsString((string) $token, EmailLog::sole()->body);

        auth('client')->logout();
        $this->post('/portal/reset-password', [
            'token' => $token, 'email' => self::BOOKKEEPER, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(self::LOGIN);
        $this->assertNotNull($contact->fresh()->password);

        $this->post(self::LOGIN, ['email' => self::BOOKKEEPER, 'password' => self::PASSWORD])->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($client, 'client');
        $this->get(self::HOME)->assertOk()->assertSee('Welcome, Pat')->assertSee('signed in to Acme Ltd');
    }

    public function test_contacts_only_open_what_their_login_allows(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::create(['client_id' => $client->id, 'currency' => 'USD', 'issue_date' => today(), 'due_date' => today()]);
        $this->logInAs($this->contact($client, ['billing']));

        $this->get(self::HOME)->assertOk()->assertSee('Invoices')->assertDontSee('Your services');
        $this->get('/portal/invoices')->assertOk();
        $this->get("/portal/invoices/{$invoice->id}")->assertOk();
        $this->get('/portal/security')->assertOk()->assertSee('a contact on this account');

        $this->get(self::SUPPORT)->assertRedirect(self::HOME)->assertSessionHasErrors('permission');
        $this->get('/portal/services')->assertRedirect(self::HOME);
        $this->get(self::ACCOUNT)->assertRedirect(self::HOME);
        $this->get('/portal/account/export')->assertRedirect(self::HOME);
        $this->post(self::CONTACTS, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com'])->assertRedirect(self::HOME);
        $this->assertSame(1, ClientContact::count());
        $this->post('/checkout')->assertRedirect('/cart')->assertSessionHasErrors('cart');
    }

    public function test_a_contact_who_loses_access_is_signed_out_without_touching_the_account_holder(): void
    {
        $client = Client::factory()->create(['password' => 'owner-password-1']);
        $client->forceFill(['remember_token' => 'owner-remember-token'])->save();
        $contact = $this->contact($client, ['support']);
        $this->logInAs($contact);

        // Contacts never get a remember-me cookie, which would sign in the account holder.
        $this->assertSame('owner-remember-token', $client->fresh()->remember_token);

        $contact->update(['portal_access' => false]);
        $this->get(self::SUPPORT)->assertRedirect(self::LOGIN);
        $this->assertGuest('client');
        $this->assertSame('owner-remember-token', $client->fresh()->remember_token);

        $this->post(self::LOGIN, ['email' => self::BOOKKEEPER, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertSame(LoginAttempt::FAILED, LoginAttempt::latest('id')->first()->outcome);
    }

    public function test_a_contact_of_a_closed_account_cannot_log_in(): void
    {
        $client = Client::factory()->create(['status' => 'closed']);
        $this->contact($client, ['billing']);

        $this->post(self::LOGIN, ['email' => self::BOOKKEEPER, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest('client');
    }

    public function test_what_a_contact_does_is_under_their_own_name(): void
    {
        $client = Client::factory()->create();
        $department = Department::create(['name' => 'Support']);
        $this->logInAs($this->contact($client, ['support']));

        $this->post(self::SUPPORT, ['department_id' => $department->id, 'subject' => 'Help', 'body' => 'Our site is down', 'priority' => 'medium'])->assertRedirect();
        $ticket = Ticket::sole();
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame('Pat Lee', $ticket->messages()->sole()->authorName());
        $this->assertSame('Pat Lee (contact)', Activity::where('description', 'like', 'Opened ticket%')->sole()->actor_name);

        $this->get('/portal/security')->assertOk()->assertSee('Signed in');
    }

    public function test_login_emails_point_at_one_person(): void
    {
        $other = Client::factory()->create(['email' => 'taken@example.com']);
        $client = Client::factory()->create();
        $this->contact(Client::factory()->create(), ['billing']);
        $this->actingAs($client, 'client');

        // A login contact can't use a client's email, or another login contact's.
        $this->post(self::CONTACTS, ['first_name' => 'A', 'last_name' => 'B', 'email' => $other->email, 'portal_access' => '1'])->assertSessionHasErrors('email');
        $this->post(self::CONTACTS, ['first_name' => 'A', 'last_name' => 'B', 'email' => self::BOOKKEEPER, 'portal_access' => '1'])->assertSessionHasErrors('email');

        // Without a login, the same bookkeeper can be on several accounts.
        $this->post(self::CONTACTS, ['first_name' => 'A', 'last_name' => 'B', 'email' => self::BOOKKEEPER])->assertSessionHasNoErrors();

        // And no client can take a login contact's email.
        $this->put(self::ACCOUNT, ['first_name' => 'A', 'last_name' => 'B', 'email' => self::BOOKKEEPER])->assertSessionHasErrors('email');
    }

    public function test_staff_give_a_contact_portal_access_and_send_the_invite_again(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $contact = $client->contacts()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->get("/admin/clients/{$client->id}")->assertSee('No portal login');
        $this->put("/admin/contacts/{$contact->id}", [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER, 'portal_access' => '1', 'permissions' => ['orders', 'services'],
        ])->assertSessionHas('status', 'Contact updated, and invited to choose a portal password.');
        Mail::assertSentCount(1);

        // Saving again doesn't send another invite; the button does.
        $this->put("/admin/contacts/{$contact->id}", [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => self::BOOKKEEPER, 'portal_access' => '1', 'permissions' => ['orders'],
        ]);
        Mail::assertSentCount(1);
        $this->get("/admin/clients/{$client->id}")->assertSee('Invited to sign in: orders')->assertSee('Send invite again');
        $this->post("/admin/contacts/{$contact->id}/invite")->assertRedirect();
        Mail::assertSentCount(2);
    }

    public function test_contacts_reset_their_own_password_from_forgot_password(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $this->contact($client, ['billing']);

        $this->post('/portal/forgot-password', ['email' => self::BOOKKEEPER])->assertSessionHas('status');
        Mail::assertSent(LoggedEmail::class, fn (LoggedEmail $mail) => $mail->hasTo(self::BOOKKEEPER) && str_contains($mail->render(), 'reset-password/'));
        $this->assertSame('password_reset', EmailLog::sole()->template);

        // A contact without a login gets nothing.
        Mail::fake();
        $client->contacts()->create(['first_name' => 'No', 'last_name' => 'Login', 'email' => 'nologin@example.com']);
        $this->post('/portal/forgot-password', ['email' => 'nologin@example.com'])->assertSessionHas('status');
        Mail::assertNothingSent();
    }

    public function test_a_contact_changes_their_own_password(): void
    {
        $contact = $this->contact(Client::factory()->create(), []);
        $this->logInAs($contact);
        $this->get(self::HOME)->assertSee('doesn\'t include invoices', false);

        $new = Str::random(16);
        $this->put('/portal/security/password', ['current_password' => 'wrong', 'password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('current_password');
        $this->put('/portal/security/password', ['current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new])->assertSessionHas('status');
        $this->post('/portal/logout');
        $this->post(self::LOGIN, ['email' => self::BOOKKEEPER, 'password' => $new])->assertRedirect(self::HOME);
    }
}
