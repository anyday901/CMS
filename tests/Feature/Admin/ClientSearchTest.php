<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientSearchTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'admin.clients.index';

    private const OPEN = 'Ada Lovelace';

    private const CLOSED = 'Charles Babbage';

    private const INACTIVE = 'Grace Hopper';

    private const CITY = 'London';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        Client::factory()->create([
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => 'Analytical Ltd', 'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0001', 'city' => self::CITY, 'country' => 'GB', 'tax_exempt' => true, 'credit_balance' => 500,
        ]);
        Client::factory()->create([
            'first_name' => 'Charles', 'last_name' => 'Babbage', 'email' => 'charles@example.com',
            'city' => self::CITY, 'country' => 'GB', 'status' => 'closed',
        ]);
        Client::factory()->create([
            'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@navy.example',
            'city' => 'Arlington', 'country' => 'US', 'status' => 'inactive',
        ])->forceFill(['created_at' => '2020-01-15 10:00:00'])->save();
    }

    public function test_closed_clients_are_hidden_by_default_with_a_count(): void
    {
        $this->get(route(self::INDEX))
            ->assertOk()
            ->assertSee(self::OPEN)->assertSee(self::INACTIVE)->assertDontSee(self::CLOSED)
            ->assertSee('Hide closed clients (1)')
            ->assertSee('2 clients found');
    }

    public function test_closed_clients_can_be_shown(): void
    {
        $this->get(route(self::INDEX, ['show_closed' => 1]))
            ->assertSee(self::CLOSED)
            ->assertSee('3 clients found');
    }

    public function test_picking_the_closed_status_lists_closed_clients(): void
    {
        $this->get(route(self::INDEX, ['status' => 'closed']))
            ->assertSee(self::CLOSED)->assertDontSee(self::OPEN)
            ->assertDontSee('Hide closed clients');
    }

    public function test_basic_filters(): void
    {
        $this->get(route(self::INDEX, ['q' => 'ada love']))->assertSee(self::OPEN)->assertDontSee(self::INACTIVE);
        $this->get(route(self::INDEX, ['q' => 'analytical']))->assertSee(self::OPEN)->assertDontSee(self::INACTIVE);
        $this->get(route(self::INDEX, ['email' => 'navy']))->assertSee(self::INACTIVE)->assertDontSee(self::OPEN);
        $this->get(route(self::INDEX, ['phone' => '7946']))->assertSee(self::OPEN)->assertDontSee(self::INACTIVE);
        $this->get(route(self::INDEX, ['status' => 'inactive']))->assertSee(self::INACTIVE)->assertDontSee(self::OPEN);
    }

    public function test_advanced_filters(): void
    {
        $this->get(route(self::INDEX, ['city' => 'london', 'show_closed' => 1]))
            ->assertSee(self::OPEN)->assertSee(self::CLOSED)->assertDontSee(self::INACTIVE);
        $this->get(route(self::INDEX, ['country' => 'US']))->assertSee(self::INACTIVE)->assertDontSee(self::OPEN);
        $this->get(route(self::INDEX, ['tax_exempt' => 'yes']))->assertSee(self::OPEN)->assertDontSee(self::INACTIVE);
        $this->get(route(self::INDEX, ['credit_balance' => 'no']))->assertSee(self::INACTIVE)->assertDontSee(self::OPEN);
        $this->get(route(self::INDEX, ['signup_to' => '2020-12-31']))->assertSee(self::INACTIVE)->assertDontSee(self::OPEN);
        $this->get(route(self::INDEX, ['signup_from' => today()->toDateString()]))->assertSee(self::OPEN)->assertDontSee(self::INACTIVE);
    }

    public function test_closed_count_follows_the_other_filters(): void
    {
        $this->get(route(self::INDEX, ['country' => 'US']))->assertSee('Hide closed clients (0)');
    }

    public function test_invalid_values_are_ignored(): void
    {
        $this->get(route(self::INDEX, ['status' => 'bogus', 'signup_from' => 'yesterday', 'credit_balance' => 'maybe']))
            ->assertOk()
            ->assertSee(self::OPEN)->assertSee(self::INACTIVE)->assertDontSee(self::CLOSED);
    }

    public function test_filters_carry_into_pagination_links(): void
    {
        Client::factory()->count(30)->create(['city' => self::CITY]);

        $this->get(route(self::INDEX, ['city' => self::CITY]))
            ->assertSee('city=London&amp;page=2', false);
    }
}
