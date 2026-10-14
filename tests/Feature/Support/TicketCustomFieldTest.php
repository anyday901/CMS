<?php

namespace Tests\Feature\Support;

use App\Models\Client;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'example.com';

    private const DOMAIN_FIELD = 'Domain name';

    private const NEW_TICKET = '/portal/support/new';

    private const STAFF_FIELD = 'Root cause';

    private Department $technical;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->technical = Department::create(['name' => 'Technical', 'active' => true]);
        $this->sales = Department::create(['name' => 'Sales', 'active' => true]);
    }

    public function test_staff_add_ticket_fields_for_a_department(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->post('/admin/custom-fields', ['entity' => 'ticket', 'department_id' => $this->technical->id, 'name' => self::DOMAIN_FIELD, 'type' => 'text', 'required' => '1'])->assertRedirect();
        $field = CustomField::sole();
        $this->assertSame([CustomField::TICKET, $this->technical->id, null], [$field->entity, $field->department_id, $field->product_id]);
        $this->get('/admin/custom-fields')->assertOk()->assertSee('Ticket fields')->assertSee('Technical');

        // A department is only kept on ticket fields.
        $this->put("/admin/custom-fields/{$field->id}", ['entity' => 'client', 'department_id' => $this->technical->id, 'name' => 'Other', 'type' => 'text'])->assertRedirect();
        $this->assertNull($field->fresh()->department_id);
    }

    public function test_clients_fill_in_the_departments_fields_when_opening_a_ticket(): void
    {
        $domain = CustomField::create(['entity' => 'ticket', 'department_id' => $this->technical->id, 'name' => self::DOMAIN_FIELD, 'type' => 'text', 'required' => true]);
        $everywhere = CustomField::create(['entity' => 'ticket', 'name' => 'Urgency reason', 'type' => 'textarea']);
        $staffOnly = CustomField::create(['entity' => 'ticket', 'name' => self::STAFF_FIELD, 'type' => 'text', 'admin_only' => true]);
        $this->actingAs(Client::factory()->create(), 'client');

        $this->get(self::NEW_TICKET)->assertOk()->assertSee('Which team can help?')->assertDontSee(self::DOMAIN_FIELD);
        $this->get(self::NEW_TICKET.'?department='.$this->sales->id)->assertOk()->assertSee('Urgency reason')->assertDontSee(self::DOMAIN_FIELD);
        $this->get(self::NEW_TICKET.'?department='.$this->technical->id)->assertOk()->assertSee(self::DOMAIN_FIELD)->assertDontSee(self::STAFF_FIELD);

        $ticket = ['department_id' => $this->technical->id, 'subject' => 'Site down', 'priority' => 'medium', 'body' => 'Help'];
        $this->post('/portal/support', $ticket)->assertSessionHasErrors("custom_fields.{$domain->id}");
        $this->post('/portal/support', $ticket + ['custom_fields' => [$domain->id => self::DOMAIN, $staffOnly->id => 'sneaky']])->assertRedirect();

        $saved = Ticket::sole();
        $this->assertSame([(string) $domain->id => self::DOMAIN], $saved->custom_fields);
        $this->get("/portal/support/{$saved->id}")->assertSee(self::DOMAIN_FIELD)->assertSee(self::DOMAIN)->assertDontSee(self::STAFF_FIELD);

        // Staff see every field, can fill the staff-only one, and can leave required ones empty.
        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/tickets/{$saved->id}")->assertOk()->assertSee(self::DOMAIN)->assertSee(self::STAFF_FIELD);
        $this->put("/admin/tickets/{$saved->id}", [
            'status' => 'open', 'priority' => 'medium', 'department_id' => $this->technical->id,
            'custom_fields' => [$domain->id => '', $everywhere->id => 'Outage', $staffOnly->id => 'Disk full'],
        ])->assertSessionHasNoErrors();
        $this->assertSame([(string) $everywhere->id => 'Outage', (string) $staffOnly->id => 'Disk full'], $saved->fresh()->custom_fields);
    }
}
