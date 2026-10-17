<?php

namespace Tests\Support;

use App\Import\Whmcs\WhmcsCrypt;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A small WHMCS database in memory, with the tables and columns the importer
 * reads, and a little sample data: one USD client with a contact, a monthly
 * cPanel product with an option and a custom field in a server group, a
 * service with its login on a cPanel server, an unpaid renewal invoice, a
 * paid invoice with its payment, and a ticket with a staff reply.
 */
class WhmcsDatabase
{
    private const OPENED_AT = '2026-10-10 09:00:00';

    public const CONNECTION = 'whmcs';

    /** WHMCS's cc_encryption_hash, which stored passwords are encrypted with. */
    public const HASH = 'Kx92lsQp0dLw7a1VbN3eRtYu';

    public const SERVER_PASSWORD = 'Whm-root-pass1';

    public const SERVICE_PASSWORD = 'Ada-cpanel-pass1';

    /** The VPS's monthly price. */
    private const PRICE = '13.00';

    private const MONTHLY = '10.00';

    private const NOVEMBER = '2026-11-15';

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

        self::clientTables($schema);
        self::productTables($schema);
        self::serviceTables($schema);
        self::ticketTables($schema);
        self::supportTables($schema);
        self::historyTables($schema);

        return $db;
    }

    private static function clientTables(Builder $schema): void
    {
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
            $t->integer('groupid')->default(0);
            $t->integer('marketing_emails_opt_in')->default(0);
        });
        $schema->create('tblclientgroups', function (Blueprint $t) {
            $t->id();
            $t->string('groupname');
            $t->string('groupcolour')->nullable();
            $t->decimal('discountpercent', 10, 2)->default(0);
            $t->text('susptermexempt')->nullable();
            $t->text('separateinvoices')->nullable();
        });
        $schema->create('tblnotes', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->integer('adminid')->default(0);
            $t->dateTime('created')->nullable();
            $t->dateTime('modified')->nullable();
            $t->text('note');
            $t->integer('sticky')->default(0);
        });
        $schema->create('tbltodolist', function (Blueprint $t) {
            $t->id();
            $t->date('date')->nullable();
            $t->text('title');
            $t->text('description')->nullable();
            $t->integer('admin')->default(0);
            $t->text('status')->nullable();
            $t->date('duedate')->nullable();
        });
        $schema->create('tblusers', function (Blueprint $t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email');
            $t->string('password')->nullable();
        });
        $schema->create('tblusers_clients', function (Blueprint $t) {
            $t->id();
            $t->integer('auth_user_id');
            $t->integer('client_id');
            $t->integer('owner')->default(0);
            $t->text('permissions')->nullable();
        });
        $schema->create('tblcontacts', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            foreach (['firstname', 'lastname', 'email', 'phonenumber', 'subaccount', 'permissions', 'invoiceemails', 'supportemails'] as $column) {
                $t->text($column)->nullable();
            }
        });
    }

    private static function productTables(Builder $schema): void
    {
        $schema->create('tblproducts', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'description', 'hidden', 'retired', 'tax', 'paytype', 'servertype', 'configoption1'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('servergroup')->default(0);
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
    }

    private static function serviceTables(Builder $schema): void
    {
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
            $t->integer('server')->default(0);
            $t->string('username')->nullable();
            $t->text('password')->nullable();
            $t->string('dedicatedip')->nullable();
            $t->integer('orderid')->default(0);
            $t->integer('promoid')->default(0);
        });
        $schema->create('tblservers', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'ipaddress', 'assignedips', 'hostname', 'type', 'username', 'password', 'accesshash', 'secure'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('port')->nullable();
            $t->integer('maxaccounts')->default(0);
            $t->integer('disabled')->default(0);
        });
        $schema->create('tblservergroups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('filltype')->default(1);
        });
        $schema->create('tblservergroupsrel', function (Blueprint $t) {
            $t->integer('groupid');
            $t->integer('serverid');
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
    }

    private static function ticketTables(Builder $schema): void
    {
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
            $t->string('email')->nullable();
            $t->dateTime('date')->nullable();
            $t->string('title');
            $t->text('message');
            $t->string('status');
            $t->string('urgency');
            $t->string('admin')->nullable();
            $t->string('attachment')->nullable();
            $t->dateTime('lastreply')->nullable();
            $t->integer('flag')->default(0);
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
    }

    private static function supportTables(Builder $schema): void
    {
        $schema->create('tbltickettags', function (Blueprint $t) {
            $t->id();
            $t->integer('ticketid');
            $t->string('tag');
        });
        $schema->create('tblknowledgebasecats', function (Blueprint $t) {
            $t->id();
            $t->integer('parentid')->default(0);
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('hidden')->nullable();
            $t->integer('catid')->default(0);
            $t->string('language')->nullable();
        });
        $schema->create('tblknowledgebase', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('article');
            $t->integer('views')->default(0);
            $t->integer('votes')->default(0);
            $t->integer('useful')->default(0);
            $t->string('private')->nullable();
            $t->integer('order')->default(0);
            $t->integer('parentid')->default(0);
            $t->string('language')->nullable();
        });
        $schema->create('tblknowledgebaselinks', function (Blueprint $t) {
            $t->id();
            $t->integer('categoryid');
            $t->integer('articleid');
        });
        $schema->create('tblknowledgebasetags', function (Blueprint $t) {
            $t->id();
            $t->integer('articleid');
            $t->string('tag');
        });
        $schema->create('tblannouncements', function (Blueprint $t) {
            $t->id();
            $t->dateTime('date')->nullable();
            $t->string('title');
            $t->text('announcement');
            $t->string('published')->nullable();
            $t->integer('parentid')->default(0);
            $t->string('language')->nullable();
        });
        $schema->create('tblnetworkissues', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description');
            $t->string('type')->nullable();
            $t->string('affecting')->nullable();
            $t->integer('server')->default(0);
            $t->string('priority')->nullable();
            $t->dateTime('startdate')->nullable();
            $t->dateTime('enddate')->nullable();
            $t->string('status')->nullable();
            $t->dateTime('lastupdate')->nullable();
        });
        $schema->create('tbldownloadcats', function (Blueprint $t) {
            $t->id();
            $t->integer('parentid')->default(0);
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('hidden')->nullable();
        });
        $schema->create('tbldownloads', function (Blueprint $t) {
            $t->id();
            $t->integer('category');
            $t->string('type')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->integer('downloads')->default(0);
            $t->string('location');
            $t->string('clientsonly')->nullable();
            $t->string('hidden')->nullable();
            $t->string('productdownload')->nullable();
        });
        $schema->create('tblproduct_downloads', function (Blueprint $t) {
            $t->id();
            $t->integer('product_id');
            $t->integer('download_id');
        });
        $schema->create('tbladmins', function (Blueprint $t) {
            $t->id();
            $t->integer('roleid')->default(1);
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('email');
            $t->integer('disabled')->default(0);
        });
        $schema->create('tbladminroles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        $schema->create('tbladminperms', function (Blueprint $t) {
            $t->id();
            $t->integer('roleid');
            $t->integer('permid');
        });
        $schema->create('tblticketfeedback', function (Blueprint $t) {
            $t->id();
            $t->integer('ticketid');
            $t->integer('adminid')->default(0);
            $t->integer('rating');
            $t->text('comments')->nullable();
            $t->dateTime('datetime')->nullable();
            $t->string('ip')->nullable();
        });
        $schema->create('tblticketescalations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            foreach (['departments', 'statuses', 'priorities', 'notify', 'addreply'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('timeelapsed')->default(0);
            $t->integer('newdepartment')->default(0);
            $t->string('newpriority')->nullable();
            $t->string('newstatus')->nullable();
            $t->integer('flagto')->default(0);
        });
    }

    private static function historyTables(Builder $schema): void
    {
        $schema->create('tblpromotions', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('type');
            $t->integer('recurring')->default(0);
            $t->decimal('value', 16, 2)->default(0);
            $t->text('appliesto')->nullable();
            $t->date('startdate')->nullable();
            $t->date('expirationdate')->nullable();
            $t->integer('maxuses')->default(0);
            $t->integer('uses')->default(0);
            $t->integer('onceperclient')->default(0);
            $t->text('notes')->nullable();
        });
        $schema->create('tblorders', function (Blueprint $t) {
            $t->id();
            $t->integer('ordernum')->default(0);
            $t->integer('userid');
            $t->dateTime('date')->nullable();
            $t->string('promocode')->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->integer('invoiceid')->default(0);
            $t->string('status');
            $t->string('ipaddress')->nullable();
        });
        $schema->create('tblcredit', function (Blueprint $t) {
            $t->id();
            $t->integer('clientid');
            $t->date('date')->nullable();
            $t->text('description')->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->integer('relid')->default(0);
        });
        $schema->create('tblcancelrequests', function (Blueprint $t) {
            $t->id();
            $t->dateTime('date')->nullable();
            $t->integer('relid');
            $t->text('reason')->nullable();
            $t->string('type');
        });
        $schema->create('tblemails', function (Blueprint $t) {
            $t->id();
            $t->integer('userid');
            $t->text('subject')->nullable();
            $t->text('message')->nullable();
            $t->dateTime('date')->nullable();
            $t->text('to')->nullable();
            $t->text('cc')->nullable();
            $t->text('bcc')->nullable();
            $t->integer('pending')->default(0);
            $t->integer('failed')->default(0);
            $t->text('failure_reason')->nullable();
        });
        $schema->create('tblactivitylog', function (Blueprint $t) {
            $t->id();
            $t->dateTime('date')->nullable();
            $t->text('description');
            $t->string('user')->nullable();
            $t->integer('userid')->default(0);
            $t->integer('user_id')->default(0);
            $t->integer('admin_id')->default(0);
            $t->string('ipaddr')->nullable();
        });
    }

    /**
     * History on top of seed(): a promotion and the order that used it for
     * the VPS, a cancelled second service with its cancellation request, a
     * credit log that adds up to Ada's balance less 1.00, and activity log
     * lines by an admin, the client and WHMCS itself.
     */
    public static function seedHistory(ConnectionInterface $db): void
    {
        $db->table('tblpromotions')->insert([
            ['id' => 5, 'code' => 'SPRING20', 'type' => 'Percentage', 'recurring' => 1, 'value' => '20.00', 'appliesto' => '3,A9,D.com', 'startdate' => '2024-01-01', 'expirationdate' => '0000-00-00', 'maxuses' => 0, 'uses' => 14, 'onceperclient' => 1, 'notes' => 'Spring sale'],
            ['id' => 6, 'code' => 'FREESETUP', 'type' => 'Free Setup', 'recurring' => 0, 'value' => '0.00', 'appliesto' => '', 'startdate' => null, 'expirationdate' => '2024-06-01', 'maxuses' => 50, 'uses' => 3, 'onceperclient' => 0, 'notes' => ''],
        ]);
        $db->table('tblorders')->insert([
            ['id' => 900, 'ordernum' => 1234567, 'userid' => 7, 'date' => '2024-01-15 08:30:00', 'promocode' => 'SPRING20', 'amount' => '10.40', 'invoiceid' => 100, 'status' => 'Active', 'ipaddress' => '198.51.100.7'],
            ['id' => 901, 'ordernum' => 7654321, 'userid' => 7, 'date' => '2025-02-01 08:30:00', 'promocode' => '', 'amount' => '5.00', 'invoiceid' => 0, 'status' => 'Fraud', 'ipaddress' => ''],
            ['id' => 902, 'ordernum' => 1111111, 'userid' => 99, 'date' => '2025-02-01 08:30:00', 'promocode' => '', 'amount' => '5.00', 'invoiceid' => 0, 'status' => 'Pending', 'ipaddress' => ''],
        ]);
        $db->table('tblhosting')->where('id', 40)->update(['orderid' => 900, 'promoid' => 5]);
        $db->table('tblhosting')->insert([
            'id' => 41, 'userid' => 7, 'packageid' => 3, 'regdate' => '2024-02-01', 'domain' => 'old.example.com', 'amount' => self::MONTHLY,
            'billingcycle' => 'Monthly', 'nextduedate' => '2025-03-01', 'termination_date' => '2025-02-20', 'domainstatus' => 'Cancelled', 'orderid' => 0, 'promoid' => 0,
        ]);
        $db->table('tblcancelrequests')->insert([
            ['date' => '2025-02-10 12:00:00', 'relid' => 41, 'reason' => 'Moving to a bigger plan', 'type' => 'End of Billing Period'],
            ['date' => '2026-10-01 12:00:00', 'relid' => 40, 'reason' => 'Too expensive', 'type' => 'Immediate'],
        ]);
        $db->table('tblcredit')->insert([
            ['clientid' => 7, 'date' => '2024-05-01', 'description' => 'Goodwill credit', 'amount' => '20.00', 'relid' => 0],
            ['clientid' => 7, 'date' => '2024-06-01', 'description' => 'Credit Applied to Invoice #100', 'amount' => '-8.50', 'relid' => 100],
        ]);
        $db->table('tblactivitylog')->insert([
            ['date' => '2024-01-15 08:31:00', 'description' => 'Order Accepted - Order ID: 900', 'user' => 'jane', 'userid' => 7, 'user_id' => 0, 'admin_id' => 1, 'ipaddr' => '203.0.113.9'],
            ['date' => '2024-01-15 08:00:00', 'description' => 'Client Logged In', 'user' => 'Client', 'userid' => 7, 'user_id' => 1, 'admin_id' => 0, 'ipaddr' => '198.51.100.7'],
            ['date' => '2024-01-16 00:05:00', 'description' => 'Cron Job: Starting', 'user' => 'System', 'userid' => 0, 'user_id' => 0, 'admin_id' => 0, 'ipaddr' => ''],
            ['date' => '2024-01-16 00:06:00', 'description' => '', 'user' => 'System', 'userid' => 0, 'user_id' => 0, 'admin_id' => 0, 'ipaddr' => ''],
        ]);
    }

    /**
     * Emails WHMCS sent on top of seed(): an invoice email to Ada with a copy
     * to Bob, an older one with no To address, one that failed, one still
     * waiting to go out, and one to a client that wasn't imported.
     */
    public static function seedEmails(ConnectionInterface $db): void
    {
        $db->table('tblemails')->insert([
            ['id' => 501, 'userid' => 7, 'subject' => 'Customer Invoice', 'message' => '<html><head><style>p { color: red; }</style></head><body><p>Dear Ada,</p><p>Invoice <strong>#100</strong> is due.</p><p><a href="https://billing.example.com/viewinvoice.php?id=100">Pay now</a></p><script>alert(1)</script></body></html>', 'date' => '2025-03-01 09:00:00', 'to' => 'ada@example.com', 'cc' => 'bob@example.com', 'bcc' => 'accounts@example.com', 'pending' => 0, 'failed' => 0, 'failure_reason' => ''],
            ['id' => 500, 'userid' => 7, 'subject' => 'Welcome &amp; thanks', 'message' => 'Thanks for signing up.', 'date' => '2020-01-02 10:00:00', 'to' => '', 'cc' => '', 'bcc' => '', 'pending' => 0, 'failed' => 0, 'failure_reason' => ''],
            ['id' => 502, 'userid' => 7, 'subject' => 'Overdue', 'message' => '<p>Late</p>', 'date' => '2025-04-01 09:00:00', 'to' => 'ada@example.com', 'cc' => '', 'bcc' => '', 'pending' => 0, 'failed' => 1, 'failure_reason' => 'SMTP Error: Could not connect'],
            ['id' => 503, 'userid' => 7, 'subject' => 'Queued', 'message' => '<p>Soon</p>', 'date' => '2025-05-01 09:00:00', 'to' => 'ada@example.com', 'cc' => '', 'bcc' => '', 'pending' => 1, 'failed' => 0, 'failure_reason' => ''],
            ['id' => 504, 'userid' => 99, 'subject' => 'Nobody', 'message' => '<p>Gone</p>', 'date' => '2025-05-01 09:00:00', 'to' => 'gone@example.com', 'cc' => '', 'bcc' => '', 'pending' => 0, 'failed' => 0, 'failure_reason' => ''],
        ]);
    }

    /**
     * Support content on top of seed(): a knowledge base with a nested
     * category, a translation and a private article; announcements; network
     * issues; downloads; a ticket custom field and tags; a second ticket
     * merged into the first; and an escalation rule.
     */
    public static function seedSupport(ConnectionInterface $db): void
    {
        $db->table('tblcustomfields')->insert(['id' => 33, 'type' => 'support', 'relid' => 2, 'fieldname' => 'domain|Domain', 'fieldtype' => 'text', 'fieldoptions' => '', 'required' => 'on', 'adminonly' => '']);
        $db->table('tblcustomfieldsvalues')->insert(['fieldid' => 33, 'relid' => 60, 'value' => 'vps1.example.com']);
        $db->table('tbltickettags')->insert([['ticketid' => 60, 'tag' => 'Outage'], ['ticketid' => 60, 'tag' => 'vps']]);
        $db->table('tbltickets')->insert([
            'id' => 61, 'tid' => '771122', 'did' => 2, 'userid' => 7, 'date' => '2026-10-01 10:30:00', 'title' => 'Still down?',
            'message' => '', 'status' => 'Closed', 'urgency' => 'High', 'admin' => '', 'lastreply' => '2026-10-01 10:30:00', 'merged_ticket_id' => 60,
        ]);

        $db->table('tblknowledgebasecats')->insert([
            ['id' => 1, 'parentid' => 0, 'name' => 'Getting started', 'description' => 'First steps', 'hidden' => '', 'catid' => 0, 'language' => ''],
            ['id' => 2, 'parentid' => 1, 'name' => 'Email', 'description' => '', 'hidden' => '', 'catid' => 0, 'language' => ''],
            ['id' => 3, 'parentid' => 2, 'name' => 'Webmail', 'description' => '', 'hidden' => '', 'catid' => 0, 'language' => ''],
            ['id' => 4, 'parentid' => 0, 'name' => 'Erste Schritte', 'description' => '', 'hidden' => '', 'catid' => 1, 'language' => 'german'],
        ]);
        $db->table('tblknowledgebase')->insert([
            ['id' => 1, 'title' => 'Reset your cPanel password', 'article' => '<p>Open <strong>cPanel</strong>.</p><ul><li>Click Password</li></ul>', 'views' => 40, 'votes' => 5, 'useful' => 4, 'private' => '', 'order' => 1, 'parentid' => 0, 'language' => ''],
            ['id' => 2, 'title' => 'cPanel Passwort', 'article' => '<p>Auf Deutsch</p>', 'views' => 0, 'votes' => 0, 'useful' => 0, 'private' => '', 'order' => 0, 'parentid' => 1, 'language' => 'german'],
            ['id' => 3, 'title' => 'Webmail settings', 'article' => '<p>IMAP</p>', 'views' => 3, 'votes' => 0, 'useful' => 0, 'private' => 'on', 'order' => 0, 'parentid' => 0, 'language' => ''],
        ]);
        $db->table('tblknowledgebaselinks')->insert([['categoryid' => 1, 'articleid' => 1], ['categoryid' => 4, 'articleid' => 2], ['categoryid' => 3, 'articleid' => 3]]);
        $db->table('tblknowledgebasetags')->insert([['articleid' => 1, 'tag' => 'password'], ['articleid' => 1, 'tag' => 'cpanel']]);

        $db->table('tblannouncements')->insert([
            ['id' => 1, 'date' => '2026-09-01 12:00:00', 'title' => 'New data centre', 'announcement' => '<p>We moved to <a href="https://example.com/dc">a new data centre</a>.</p>', 'published' => 'on', 'parentid' => 0, 'language' => ''],
            ['id' => 2, 'date' => '2026-09-01 12:00:00', 'title' => 'Neues Rechenzentrum', 'announcement' => '<p>...</p>', 'published' => 'on', 'parentid' => 1, 'language' => 'german'],
            ['id' => 3, 'date' => '2026-09-05 12:00:00', 'title' => 'Draft news', 'announcement' => '<p>Soon</p>', 'published' => '', 'parentid' => 0, 'language' => ''],
        ]);

        $db->table('tblnetworkissues')->insert([
            ['title' => 'cp1 reboot', 'description' => 'Kernel update', 'type' => 'Server', 'affecting' => '', 'server' => 1, 'priority' => 'High', 'startdate' => '2026-10-20 02:00:00', 'enddate' => null, 'status' => 'Scheduled', 'lastupdate' => self::OPENED_AT],
            ['title' => 'Email delays', 'description' => 'Queue backed up', 'type' => 'System', 'affecting' => 'Email', 'server' => 0, 'priority' => 'Critical', 'startdate' => '2026-09-10 08:00:00', 'enddate' => null, 'status' => 'Resolved', 'lastupdate' => '2026-09-10 10:00:00'],
        ]);

        $db->table('tbldownloadcats')->insert([['id' => 1, 'parentid' => 0, 'name' => 'Software', 'description' => 'Tools', 'hidden' => ''], ['id' => 2, 'parentid' => 0, 'name' => 'Old', 'description' => '', 'hidden' => 'on'], ['id' => 3, 'parentid' => 2, 'name' => 'Old tools', 'description' => '', 'hidden' => '']]);
        $db->table('tbldownloads')->insert([
            ['id' => 1, 'category' => 1, 'type' => 'zip', 'title' => 'Backup tool', 'description' => 'Backs up', 'downloads' => 12, 'location' => 'backup-tool.zip', 'clientsonly' => 'on', 'hidden' => '', 'productdownload' => 'on'],
            ['id' => 2, 'category' => 1, 'type' => 'link', 'title' => 'Docs site', 'description' => '', 'downloads' => 0, 'location' => 'https://docs.example.com', 'clientsonly' => '', 'hidden' => '', 'productdownload' => ''],
            ['id' => 3, 'category' => 3, 'type' => 'zip', 'title' => 'Ancient', 'description' => '', 'downloads' => 0, 'location' => 'ancient.zip', 'clientsonly' => '', 'hidden' => '', 'productdownload' => ''],
        ]);
        $db->table('tblproduct_downloads')->insert(['product_id' => 3, 'download_id' => 1]);

        $db->table('tbladmins')->insert([['id' => 1, 'email' => 'jane@example.com'], ['id' => 2, 'email' => 'gone@example.com']]);
        $db->table('tblticketescalations')->insert([
            'name' => 'Urgent waiting', 'departments' => '2', 'statuses' => 'Open,Customer-Reply,In Progress', 'priorities' => 'High', 'timeelapsed' => 60,
            'newdepartment' => 2, 'newpriority' => 'Urgent', 'newstatus' => '', 'flagto' => 1, 'notify' => '2,assigned', 'addreply' => 'We are on it.',
        ]);
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

        $crypt = new WhmcsCrypt(self::HASH);
        $db->table('tblservers')->insert([
            ['id' => 1, 'name' => 'cp1', 'ipaddress' => '203.0.113.5', 'hostname' => 'cp1.example.test', 'type' => 'cpanel', 'username' => 'root', 'password' => $crypt->encrypt(self::SERVER_PASSWORD), 'accesshash' => "ABC\nDEF", 'secure' => 'on', 'port' => null, 'maxaccounts' => 300, 'disabled' => 0],
            ['id' => 2, 'name' => 'Old box', 'ipaddress' => '', 'hostname' => 'old.example.test', 'type' => 'interworx', 'username' => '', 'password' => '', 'accesshash' => '', 'secure' => '', 'port' => null, 'maxaccounts' => 0, 'disabled' => 1],
        ]);
        $db->table('tblservergroups')->insert(['id' => 4, 'name' => 'Shared hosting', 'filltype' => 2]);
        $db->table('tblservergroupsrel')->insert([['groupid' => 4, 'serverid' => 1], ['groupid' => 4, 'serverid' => 2]]);

        $db->table('tblproducts')->insert(['id' => 3, 'name' => 'VPS Small', 'description' => 'Fast<br>Cheap', 'tax' => '1', 'paytype' => 'recurring', 'servertype' => 'cpanel', 'configoption1' => 'gold', 'servergroup' => 4]);
        $db->table('tblpricing')->insert(['type' => 'product', 'currency' => 1, 'relid' => 3, 'monthly' => self::MONTHLY, 'msetupfee' => '5.00', 'annually' => '100.00']);

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
            'id' => 40, 'userid' => 7, 'packageid' => 3, 'regdate' => '2024-01-15', 'domain' => 'vps1.example.com', 'amount' => self::PRICE,
            'billingcycle' => 'Monthly', 'nextduedate' => self::NOVEMBER, 'domainstatus' => 'Active',
            'server' => 1, 'username' => 'ada', 'password' => $crypt->encrypt(self::SERVICE_PASSWORD), 'dedicatedip' => '',
        ]);
        $db->table('tblhostingconfigoptions')->insert(['relid' => 40, 'configid' => 11, 'optionid' => 22, 'qty' => 0]);

        // Paid last month, with a PayPal payment.
        $db->table('tblinvoices')->insert([
            'id' => 100, 'userid' => 7, 'date' => '2026-10-08', 'duedate' => '2026-10-15', 'datepaid' => self::OPENED_AT,
            'subtotal' => self::PRICE, 'total' => self::PRICE, 'status' => 'Paid',
        ]);
        $db->table('tblinvoiceitems')->insert(['invoiceid' => 100, 'userid' => 7, 'type' => 'Hosting', 'relid' => 40, 'description' => 'VPS Small - vps1.example.com (15/10/2026 - 14/11/2026)', 'amount' => self::PRICE, 'taxed' => 1, 'duedate' => '2026-10-15']);
        $db->table('tblaccounts')->insert(['id' => 500, 'userid' => 7, 'currency' => 0, 'gateway' => 'paypalcheckout', 'date' => self::OPENED_AT, 'amountin' => self::PRICE, 'fees' => '0.68', 'transid' => 'PAY-1', 'invoiceid' => 100]);

        // This month's renewal, unpaid, with part paid from credit.
        $db->table('tblinvoices')->insert([
            'id' => 101, 'userid' => 7, 'invoicenum' => 'INV-0042', 'date' => '2026-11-08', 'duedate' => self::NOVEMBER,
            'subtotal' => self::PRICE, 'credit' => '3.00', 'total' => self::MONTHLY, 'status' => 'Unpaid',
        ]);
        $db->table('tblinvoiceitems')->insert(['invoiceid' => 101, 'userid' => 7, 'type' => 'Hosting', 'relid' => 40, 'description' => 'VPS Small - vps1.example.com (15/11/2026 - 14/12/2026)', 'amount' => self::PRICE, 'taxed' => 1, 'duedate' => self::NOVEMBER]);

        $db->table('tblticketdepartments')->insert(['id' => 2, 'name' => 'Support', 'email' => 'support@example.com']);
        $db->table('tbltickets')->insert([
            'id' => 60, 'tid' => '482913', 'did' => 2, 'userid' => 7, 'date' => '2026-10-01 10:00:00', 'title' => 'Server down',
            'message' => 'It&#039;s down', 'status' => 'Answered', 'urgency' => 'High', 'admin' => '', 'attachment' => '123456_screen.png', 'lastreply' => '2026-10-01 11:00:00',
        ]);
        $db->table('tblticketreplies')->insert(['tid' => 60, 'date' => '2026-10-01 11:00:00', 'message' => 'Fixed it.', 'admin' => 'Jane Admin']);
    }
}
