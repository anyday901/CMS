<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * A small WHMCS database in memory, with the tables and columns the importer
 * reads, and a little sample data: one USD client with a contact, a monthly
 * product with an option and a custom field, a service, an unpaid renewal
 * invoice, a paid invoice with its payment, and a ticket with a staff reply.
 */
class WhmcsDatabase
{
    public const CONNECTION = 'whmcs';

    public static function create(): ConnectionInterface
    {
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge(self::CONNECTION);

        $db = DB::connection(self::CONNECTION);
        $schema = $db->getSchemaBuilder();

        $schema->create('tblcurrencies', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->integer('default')->default(0);
        });
        $schema->create('tblconfiguration', function (Blueprint $t) {
            $t->string('setting');
            $t->text('value')->nullable();
        });
        $schema->create('tblclients', function (Blueprint $t) {
            $t->id();
            foreach (['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'password', 'status', 'notes', 'taxexempt'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('currency')->default(1);
            $t->decimal('credit', 16, 2)->default(0);
            $t->date('datecreated')->nullable();
        });
        $schema->create('tblusers', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('password')->nullable();
        });
        $schema->create('tblusers_clients', function (Blueprint $t) {
            $t->id();
            $t->integer('auth_user_id');
            $t->integer('client_id');
            $t->integer('owner')->default(0);
        });
        $schema->create('tblcontacts', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            foreach (['firstname', 'lastname', 'email', 'phonenumber', 'subaccount', 'invoiceemails', 'supportemails'] as $column) {
                $t->text($column)->nullable();
            }
        });
        $schema->create('tblproducts', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'description', 'hidden', 'retired', 'tax', 'paytype', 'servertype'] as $column) {
                $t->text($column)->nullable();
            }
        });
        $schema->create('tblpricing', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->integer('currency');
            $t->integer('relid');
            foreach (['msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee'] as $column) {
                $t->decimal($column, 16, 2)->default(0);
            }
            foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $column) {
                $t->decimal($column, 16, 2)->default(-1);
            }
        });
        $schema->create('tblproductconfiglinks', function (Blueprint $t) {
            $t->id();
            $t->integer('gid');
            $t->integer('pid');
        });
        $schema->create('tblproductconfigoptions', function (Blueprint $t) {
            $t->id();
            $t->integer('gid');
            $t->string('optionname');
            $t->integer('optiontype');
            $t->integer('qtyminimum')->default(0);
            $t->integer('qtymaximum')->default(0);
            $t->integer('order')->default(0);
        });
        $schema->create('tblproductconfigoptionssub', function (Blueprint $t) {
            $t->id();
            $t->integer('configid');
            $t->string('optionname');
            $t->integer('sortorder')->default(0);
        });
        $schema->create('tblhostingconfigoptions', function (Blueprint $t) {
            $t->id();
            $t->integer('relid');
            $t->integer('configid');
            $t->integer('optionid');
            $t->integer('qty')->default(0);
        });
        $schema->create('tblcustomfields', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->integer('relid')->default(0);
            foreach (['fieldname', 'fieldtype', 'description', 'fieldoptions', 'adminonly', 'required'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('sortorder')->default(0);
        });
        $schema->create('tblcustomfieldsvalues', function (Blueprint $t) {
            $t->id();
            $t->integer('fieldid');
            $t->integer('relid');
            $t->text('value')->nullable();
        });
        $schema->create('tblhosting', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->integer('packageid');
            $t->date('regdate')->nullable();
            $t->string('domain')->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->string('billingcycle');
            $t->date('nextduedate')->nullable();
            $t->date('termination_date')->nullable();
            $t->string('domainstatus');
            $t->string('suspendreason')->nullable();
        });
        $schema->create('tblinvoices', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->string('invoicenum')->nullable();
            $t->date('date')->nullable();
            $t->date('duedate')->nullable();
            $t->dateTime('datepaid')->nullable();
            foreach (['subtotal', 'credit', 'tax', 'tax2', 'total', 'taxrate', 'taxrate2'] as $column) {
                $t->decimal($column, 16, 2)->default(0);
            }
            $t->string('status');
            $t->text('notes')->nullable();
        });
        $schema->create('tblinvoiceitems', function (Blueprint $t) {
            $t->id();
            $t->integer('invoiceid');
            $t->integer('userid');
            $t->string('type')->nullable();
            $t->integer('relid')->default(0);
            $t->text('description');
            $t->decimal('amount', 16, 2);
            $t->integer('taxed')->default(0);
            $t->date('duedate')->nullable();
        });
        $schema->create('tblaccounts', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->integer('currency')->default(0);
            $t->string('gateway')->nullable();
            $t->dateTime('date')->nullable();
            $t->string('description')->nullable();
            $t->decimal('amountin', 16, 2)->default(0);
            $t->decimal('fees', 16, 2)->default(0);
            $t->decimal('amountout', 16, 2)->default(0);
            $t->string('transid')->nullable();
            $t->integer('invoiceid')->default(0);
            $t->integer('refundid')->default(0);
        });
        $schema->create('tblhostingaddons', function (Blueprint $t) {
            $t->id();
            $t->integer('hostingid');
            $t->string('name')->nullable();
            $t->decimal('recurring', 16, 2)->default(0);
            $t->string('billingcycle')->nullable();
            $t->string('status');
        });
        $schema->create('tbldomains', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->string('domain');
            $t->decimal('recurringamount', 16, 2)->default(0);
            $t->date('nextduedate')->nullable();
            $t->string('status');
        });
        $schema->create('tblticketdepartments', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('hidden')->nullable();
        });
        $schema->create('tbltickets', function (Blueprint $t) {
            $t->id();
            $t->string('tid');
            $t->integer('did');
            $t->integer('userid');
            $t->string('name')->nullable();
            $t->dateTime('date')->nullable();
            $t->string('title');
            $t->text('message');
            $t->string('status');
            $t->string('urgency');
            $t->string('admin')->nullable();
            $t->string('attachment')->nullable();
            $t->dateTime('lastreply')->nullable();
            $t->integer('merged_ticket_id')->default(0);
        });
        $schema->create('tblticketreplies', function (Blueprint $t) {
            $t->id();
            $t->integer('tid');
            $t->integer('userid')->default(0);
            $t->string('name')->nullable();
            $t->dateTime('date')->nullable();
            $t->text('message');
            $t->string('admin')->nullable();
            $t->string('attachment')->nullable();
        });

        return $db;
    }

    /** The sample data described above. */
    public static function seed(ConnectionInterface $db): void
    {
        $db->table('tblcurrencies')->insert([['id' => 1, 'code' => 'USD', 'default' => 1], ['id' => 2, 'code' => 'EUR', 'default' => 0]]);
        $db->table('tblconfiguration')->insert(['setting' => 'TaxType', 'value' => 'Exclusive']);

        $db->table('tblclients')->insert([
            'id' => 7, 'firstname' => 'Ada', 'lastname' => 'O&#039;Neil', 'companyname' => 'Ada &amp; Co', 'email' => 'Ada@Example.com',
            'country' => 'US', 'status' => 'Active', 'currency' => 1, 'credit' => '12.50', 'password' => '', 'datecreated' => '2020-01-02',
        ]);
        $db->table('tblusers')->insert(['id' => 1, 'email' => 'ada@example.com', 'password' => password_hash('old-secret', PASSWORD_BCRYPT)]);
        $db->table('tblusers_clients')->insert(['auth_user_id' => 1, 'client_id' => 7, 'owner' => 1]);
        $db->table('tblcontacts')->insert(['userid' => 7, 'firstname' => 'Bob', 'lastname' => 'Books', 'email' => 'bob@example.com', 'invoiceemails' => '1', 'supportemails' => '0']);

        $db->table('tblproducts')->insert(['id' => 3, 'name' => 'VPS Small', 'description' => 'Fast<br>Cheap', 'tax' => '1', 'paytype' => 'recurring', 'servertype' => 'cpanel']);
        $db->table('tblpricing')->insert(['type' => 'product', 'currency' => 1, 'relid' => 3, 'monthly' => '10.00', 'msetupfee' => '5.00', 'annually' => '100.00']);

        $db->table('tblproductconfiglinks')->insert(['gid' => 1, 'pid' => 3]);
        $db->table('tblproductconfigoptions')->insert(['id' => 11, 'gid' => 1, 'optionname' => 'ram|Memory', 'optiontype' => 1]);
        $db->table('tblproductconfigoptionssub')->insert([['id' => 21, 'configid' => 11, 'optionname' => '1GB|1 GB'], ['id' => 22, 'configid' => 11, 'optionname' => '2GB|2 GB']]);
        $db->table('tblpricing')->insert(['type' => 'configoptions', 'currency' => 1, 'relid' => 22, 'monthly' => '3.00', 'annually' => '30.00']);

        $db->table('tblcustomfields')->insert([
            ['id' => 31, 'type' => 'client', 'relid' => 0, 'fieldname' => 'VAT number', 'fieldtype' => 'text', 'fieldoptions' => '', 'required' => '', 'adminonly' => ''],
            ['id' => 32, 'type' => 'product', 'relid' => 3, 'fieldname' => 'os|Operating system', 'fieldtype' => 'dropdown', 'fieldoptions' => 'Debian,Ubuntu', 'required' => 'on', 'adminonly' => ''],
        ]);
        $db->table('tblcustomfieldsvalues')->insert([['fieldid' => 31, 'relid' => 7, 'value' => 'GB123'], ['fieldid' => 32, 'relid' => 40, 'value' => 'Debian']]);

        $db->table('tblhosting')->insert([
            'id' => 40, 'userid' => 7, 'packageid' => 3, 'regdate' => '2024-01-15', 'domain' => 'vps1.example.com', 'amount' => '13.00',
            'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-15', 'domainstatus' => 'Active',
        ]);
        $db->table('tblhostingconfigoptions')->insert(['relid' => 40, 'configid' => 11, 'optionid' => 22, 'qty' => 0]);

        // Paid last month, with a PayPal payment.
        $db->table('tblinvoices')->insert([
            'id' => 100, 'userid' => 7, 'date' => '2026-10-08', 'duedate' => '2026-10-15', 'datepaid' => '2026-10-10 09:00:00',
            'subtotal' => '13.00', 'total' => '13.00', 'status' => 'Paid',
        ]);
        $db->table('tblinvoiceitems')->insert(['invoiceid' => 100, 'userid' => 7, 'type' => 'Hosting', 'relid' => 40, 'description' => 'VPS Small - vps1.example.com (15/10/2026 - 14/11/2026)', 'amount' => '13.00', 'taxed' => 1, 'duedate' => '2026-10-15']);
        $db->table('tblaccounts')->insert(['id' => 500, 'userid' => 7, 'currency' => 0, 'gateway' => 'paypalcheckout', 'date' => '2026-10-10 09:00:00', 'amountin' => '13.00', 'fees' => '0.68', 'transid' => 'PAY-1', 'invoiceid' => 100]);

        // This month's renewal, unpaid, with part paid from credit.
        $db->table('tblinvoices')->insert([
            'id' => 101, 'userid' => 7, 'invoicenum' => 'INV-0042', 'date' => '2026-11-08', 'duedate' => '2026-11-15',
            'subtotal' => '13.00', 'credit' => '3.00', 'total' => '10.00', 'status' => 'Unpaid',
        ]);
        $db->table('tblinvoiceitems')->insert(['invoiceid' => 101, 'userid' => 7, 'type' => 'Hosting', 'relid' => 40, 'description' => 'VPS Small - vps1.example.com (15/11/2026 - 14/12/2026)', 'amount' => '13.00', 'taxed' => 1, 'duedate' => '2026-11-15']);

        $db->table('tblticketdepartments')->insert(['id' => 2, 'name' => 'Support', 'email' => 'support@example.com']);
        $db->table('tbltickets')->insert([
            'id' => 60, 'tid' => '482913', 'did' => 2, 'userid' => 7, 'date' => '2026-10-01 10:00:00', 'title' => 'Server down',
            'message' => 'It&#039;s down', 'status' => 'Answered', 'urgency' => 'High', 'admin' => '', 'attachment' => '123456_screen.png', 'lastreply' => '2026-10-01 11:00:00',
        ]);
        $db->table('tblticketreplies')->insert(['tid' => 60, 'date' => '2026-10-01 11:00:00', 'message' => 'Fixed it.', 'admin' => 'Jane Admin']);
    }
}
