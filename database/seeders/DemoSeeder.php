<?php

namespace Database\Seeders;

use App\Billing\InvoiceEditor;
use App\Billing\PaymentRecorder;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\StaffRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Department;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sample data for the public demo (`php artisan demo:reset`): a staff login
 * and a client login, products, services, invoices, tickets and help pages.
 * Written out by hand so it runs without the dev dependencies.
 */
class DemoSeeder extends Seeder
{
    private const PEOPLE = [
        ['Ada', 'Lovelace', 'Analytical Engines Ltd', 'GB', 'London'],
        ['Grace', 'Hopper', 'Cobol Consulting', 'US', 'Arlington'],
        ['Linus', 'Berg', null, 'SE', 'Stockholm'],
        ['Marie', 'Dubois', 'Atelier Dubois', 'FR', 'Lyon'],
        ['Jonas', 'Weber', 'Weber Medien GmbH', 'DE', 'Hamburg'],
        ['Lucía', 'García', null, 'ES', 'Valencia'],
        ['Kenji', 'Sato', 'Sato Design', 'JP', 'Osaka'],
        ['Amara', 'Okafor', 'Okafor Logistics', 'NG', 'Lagos'],
    ];

    public function run(InvoiceEditor $invoices, PaymentRecorder $payments): void
    {
        $password = (string) config('demo.password');

        User::create(['name' => 'Demo Admin', 'email' => config('demo.staff_email'), 'password' => Hash::make($password), 'role' => StaffRole::Admin]);
        User::create(['name' => 'Sam Support', 'email' => 'support@example.com', 'password' => Hash::make($password), 'role' => StaffRole::Support]);

        Settings::save([
            'billing.company.name' => 'Harbor Hosting (demo)',
            'billing.company.email' => 'billing@example.com',
            'billing.company.address' => "1 Quay Street\nPort Town",
            'help.languages' => ['de', 'fr', 'es'],
            'mail.default' => 'log',
        ]);

        $products = $this->products();
        $support = Department::create(['name' => 'Support', 'email' => 'support@example.com', 'active' => true]);
        Department::create(['name' => 'Billing', 'email' => 'billing@example.com', 'active' => true]);

        $client = Client::create([
            'first_name' => 'Casey', 'last_name' => 'Client', 'company' => 'Demo Bakery', 'email' => config('demo.client_email'),
            'password' => Hash::make($password), 'country' => 'US', 'city' => 'Portland', 'currency' => 'USD', 'status' => 'active',
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();
        $clients = collect([$client]);

        foreach (self::PEOPLE as $i => [$first, $last, $company, $country, $city]) {
            $clients->push(Client::create([
                'first_name' => $first, 'last_name' => $last, 'company' => $company, 'country' => $country, 'city' => $city,
                'email' => strtolower($first).'.'.strtolower(str_replace('í', 'i', $last)).'@example.com', 'currency' => 'USD', 'status' => 'active',
                'language' => ['FR' => 'fr', 'DE' => 'de', 'ES' => 'es'][$country] ?? null,
            ]));
        }

        foreach ($clients as $i => $owner) {
            $product = $products[$i % count($products)];
            $price = $product->prices()->where('billing_cycle', BillingCycle::Monthly->value)->value('price') ?? 1000;

            Service::create([
                'client_id' => $owner->id, 'product_id' => $product->id, 'label' => $i === 0 ? 'bakery.example' : null,
                'billing_cycle' => BillingCycle::Monthly, 'recurring_amount' => $price, 'status' => $i === 3 ? ServiceStatus::Suspended : ServiceStatus::Active,
                'registration_date' => today()->subMonths(6 + $i), 'next_due_date' => today()->addDays(5 + $i * 3),
            ]);

            foreach ([3, 2, 1, 0] as $monthsAgo) {
                $invoice = $invoices->create($owner, [
                    'issue_date' => today()->subMonths($monthsAgo)->toDateString(),
                    'due_date' => today()->subMonths($monthsAgo)->addDays(7)->toDateString(),
                    'notes' => null,
                    'items' => [['description' => $product->name.' (monthly)', 'amount' => $price, 'taxable' => false]],
                ], draft: false);

                if ($monthsAgo > 0 && ! ($i === 3 && $monthsAgo === 1)) {
                    $payments->record($invoice, $invoice->balance(), 'manual', 'DEMO-'.$invoice->id, method: 'bank');
                }
            }
        }

        $this->tickets($client, $clients, $support);
        $this->help();
    }

    /** @return list<Product> */
    private function products(): array
    {
        $hosting = ProductGroup::create(['name' => 'Web hosting', 'slug' => 'hosting', 'description' => 'Fast, simple hosting for websites and shops.']);
        $servers = ProductGroup::create(['name' => 'Virtual servers', 'slug' => 'servers', 'description' => 'Your own server, with root access.']);

        return collect([
            [$hosting, 'Starter hosting', '10 GB of storage, one website, free TLS certificate.', 500],
            [$hosting, 'Business hosting', '50 GB of storage, unlimited websites, daily backups.', 1500],
            [$servers, 'VPS 2 GB', '2 vCPUs, 2 GB of memory, 40 GB of NVMe storage.', 1200],
            [$servers, 'VPS 8 GB', '4 vCPUs, 8 GB of memory, 160 GB of NVMe storage.', 3900],
        ])->map(function (array $row, int $i) {
            [$group, $name, $description, $monthly] = $row;
            $product = Product::create(['product_group_id' => $group->id, 'name' => $name, 'description' => $description, 'active' => true, 'taxable' => false, 'sort_order' => $i]);
            $product->prices()->create(['billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'price' => $monthly, 'setup_fee' => 0]);
            $product->prices()->create(['billing_cycle' => BillingCycle::Annually, 'currency' => 'USD', 'price' => $monthly * 10, 'setup_fee' => 0]);

            return $product;
        })->all();
    }

    private function tickets(Client $client, $clients, Department $support): void
    {
        $staff = User::where('email', config('demo.staff_email'))->first();

        foreach ([
            [$client, 'Can I point a second domain at my site?', 'Hi, we bought a second domain for the bakery. Can it show the same website?', TicketStatus::Answered, 'Yes, add it as an alias under Domains in your control panel, then point its nameservers at ours. It can take a few hours to work everywhere.'],
            [$clients[1], 'Invoice address', 'Please put our VAT number on future invoices.', TicketStatus::Open, null],
            [$clients[2], 'Server is slow since this morning', 'Pages take 10 seconds to load. Did something change?', TicketStatus::CustomerReply, 'We\'re looking into it now.'],
        ] as [$owner, $subject, $body, $status, $reply]) {
            $ticket = Ticket::create(['client_id' => $owner->id, 'department_id' => $support->id, 'subject' => $subject, 'status' => $status, 'priority' => TicketPriority::Medium, 'last_reply_at' => now()]);
            $ticket->messages()->create(['client_id' => $owner->id, 'author_name' => $owner->fullName(), 'body' => $body, 'via' => 'portal']);

            if ($reply) {
                $ticket->messages()->create(['user_id' => $staff->id, 'author_name' => $staff->name, 'body' => $reply, 'via' => 'admin']);
            }
        }
    }

    private function help(): void
    {
        $category = KbCategory::create(['name' => 'Getting started', 'description' => 'Your first steps with your new service.']);
        KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Uploading your website', 'body' => "Connect with SFTP to the server name in your welcome email, and upload your files to the `public_html` folder.\n\nYour site shows up straight away.", 'published' => true]);
        KbArticle::create(['kb_category_id' => $category->id, 'title' => 'Setting up email on your phone', 'body' => 'Use IMAP with the server name in your welcome email, port 993, and your full email address as the username.', 'published' => true]);
        Announcement::create(['title' => 'Welcome to the demo', 'body' => 'Look around the portal and the admin area. Everything resets every few hours.', 'published' => true, 'published_at' => now()]);
    }
}
