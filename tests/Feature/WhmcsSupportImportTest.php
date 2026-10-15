<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Import\Whmcs\HtmlToMarkdown;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\CustomField;
use App\Models\Download;
use App\Models\EscalationRule;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\NetworkIssue;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhmcsDatabase;
use Tests\TestCase;

class WhmcsSupportImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $whmcs;

    private string $downloads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->whmcs = WhmcsDatabase::create();
        WhmcsDatabase::seed($this->whmcs);
        WhmcsDatabase::seedSupport($this->whmcs);

        $this->downloads = sys_get_temp_dir().'/whmcs-downloads-'.uniqid();
        mkdir($this->downloads);
        file_put_contents($this->downloads.'/backup-tool.zip', 'ZIPDATA');
    }

    protected function tearDown(): void
    {
        @unlink($this->downloads.'/backup-tool.zip');
        @rmdir($this->downloads);
        parent::tearDown();
    }

    public function test_the_knowledge_base_comes_over_as_markdown(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertSame(['Email', 'Getting started', 'Webmail'], KbCategory::orderBy('name')->pluck('name')->all());
        $top = KbCategory::where('name', 'Getting started')->first();
        // Webmail was nested two deep, so it moved up under the top-level category.
        $this->assertSame($top->id, KbCategory::where('name', 'Webmail')->value('parent_id'));
        $this->assertSame($top->id, KbCategory::where('name', 'Email')->value('parent_id'));

        $article = KbArticle::where('title', 'Reset your cPanel password')->first();
        $this->assertSame("Open **cPanel**.\n\n- Click Password", $article->body);
        $this->assertSame('password, cpanel', $article->tags);
        $this->assertSame([40, 4, 1], [$article->views, $article->helpful, $article->not_helpful]);
        $this->assertTrue($article->published);
        $this->assertFalse(KbArticle::where('title', 'Webmail settings')->value('published'));
        $this->assertSame(2, KbArticle::count());

        $this->get(route('kb.article', $article))->assertOk()->assertSee('<strong>cPanel</strong>', false);
    }

    public function test_announcements_and_network_issues_come_over(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $this->assertSame(['Draft news', 'New data centre'], Announcement::orderBy('title')->pluck('title')->all());
        $news = Announcement::where('title', 'New data centre')->first();
        $this->assertTrue($news->published);
        $this->assertSame('We moved to [a new data centre](https://example.com/dc).', $news->body);
        $this->assertSame('2026-09-01 12:00', $news->published_at->format('Y-m-d H:i'));
        $this->assertFalse(Announcement::where('title', 'Draft news')->value('published'));

        $reboot = NetworkIssue::where('title', 'cp1 reboot')->first();
        $this->assertSame(Server::where('name', 'cp1')->value('id'), $reboot->server_id);
        $this->assertSame(['high', NetworkIssue::SCHEDULED], [$reboot->priority, $reboot->status]);

        $email = NetworkIssue::where('title', 'Email delays')->first();
        $this->assertSame(['Email', 'critical', NetworkIssue::RESOLVED], [$email->affecting, $email->priority, $email->status]);
        $this->assertSame('2026-09-10 10:00', $email->ends_at->format('Y-m-d H:i'));
    }

    public function test_downloads_come_over_with_their_files_from_the_downloads_folder(): void
    {
        $report = (new WhmcsImporter(downloadsPath: $this->downloads))->run($this->whmcs, dryRun: false);

        $download = Download::with('products')->sole();
        $this->assertSame(['Backup tool', 'backup-tool.zip', 7, 12], [$download->title, $download->file_name, $download->size, $download->downloads]);
        $this->assertSame(['VPS Small'], $download->products->pluck('name')->all());
        $this->assertTrue($download->clients_only);
        $this->assertSame('ZIPDATA', Storage::disk('local')->get($download->path));
        $this->assertSame(['found' => 3, 'imported' => 1, 'skipped' => 2], $report->counts()['Downloads']);
        $this->assertStringContainsString('links to https://docs.example.com', $report->toMarkdown());
        // "Old tools" is visible itself but sits under the hidden "Old", so it stays out too.
        $this->assertSame(['found' => 3, 'imported' => 1, 'skipped' => 2], $report->counts()['Download categories']);
        $this->assertStringContainsString('"Old tools" is hidden in WHMCS, or sits under a hidden category', $report->toMarkdown());
    }

    public function test_downloads_need_the_folder(): void
    {
        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);
        $this->assertSame(0, Download::count());
        $this->assertStringContainsString('needs the WHMCS downloads folder', $report->toMarkdown());
    }

    public function test_a_dry_run_copies_no_download_files(): void
    {
        $report = (new WhmcsImporter(downloadsPath: $this->downloads))->run($this->whmcs, dryRun: true);
        $this->assertSame(1, $report->counts()['Downloads']['imported']);
        $this->assertSame(0, Download::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_ticket_fields_tags_and_merged_tickets_come_over(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $field = CustomField::where('entity', CustomField::TICKET)->sole();
        $this->assertSame(['Domain', true], [$field->name, $field->required]);
        $this->assertNotNull($field->department_id);

        $ticket = Ticket::find(60);
        $this->assertSame([(string) $field->id => 'vps1.example.com'], $ticket->custom_fields);
        $this->assertSame(['outage', 'vps'], $ticket->tags->pluck('name')->all());

        $merged = Ticket::find(61);
        $this->assertSame([60, TicketStatus::Closed], [$merged->merged_into_id, $merged->status]);
        $this->assertSame(7, $merged->client_id);

        // The old number leads to the ticket it was merged into.
        $this->actingAs(Client::find(7), 'client')->get('/portal/support/61')->assertRedirect(route('portal.tickets.show', 60));
        $this->assertSame([60], Ticket::listed()->pluck('id')->all());
    }

    public function test_escalation_rules_come_over_turned_off_with_staff_matched_by_email(): void
    {
        $jane = User::factory()->create(['email' => 'Jane@Example.com']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $rule = EscalationRule::sole();
        $this->assertFalse($rule->active);
        $this->assertSame('Urgent waiting', $rule->name);
        $this->assertSame([Ticket::find(60)->department_id], $rule->department_ids);
        $this->assertSame([TicketStatus::Open->value, TicketStatus::CustomerReply->value], $rule->statuses);
        $this->assertSame([TicketPriority::High->value], $rule->priorities);
        $this->assertSame(60, $rule->minutes_since_reply);
        $this->assertSame(TicketPriority::Urgent, $rule->set_priority);
        $this->assertNull($rule->set_status);
        $this->assertSame($jane->id, $rule->assign_to);
        $this->assertTrue($rule->notify_assignee);
        // The other admin had no staff account, so the import made one.
        $this->assertSame([User::where('email', 'gone@example.com')->value('id')], $rule->notify_user_ids);
        $this->assertSame('We are on it.', $rule->reply);

        $markdown = $report->toMarkdown();
        $this->assertStringContainsString('"In Progress" doesn\'t exist here', $markdown);
        $this->assertStringContainsString('imported turned off', $markdown);
    }

    public function test_admins_come_over_as_staff_with_their_passwords_assignments_and_replies(): void
    {
        $this->whmcs->table('tbladminroles')->insert([['id' => 1, 'name' => 'Full Administrator'], ['id' => 2, 'name' => 'Account Manager'], ['id' => 3, 'name' => 'Support Operator']]);
        $this->whmcs->table('tbladmins')->where('id', 1)->update(['firstname' => 'Jane', 'lastname' => 'Admin', 'roleid' => 3, 'password' => password_hash('whmcs-pass', PASSWORD_BCRYPT)]);
        $this->whmcs->table('tbladmins')->where('id', 2)->update(['firstname' => 'Gary', 'lastname' => 'Gone', 'roleid' => 2, 'password' => md5('old')]);
        $this->whmcs->table('tbladmins')->insert([
            ['id' => 3, 'firstname' => 'Old', 'lastname' => 'Timer', 'email' => 'old@example.com', 'roleid' => 1, 'password' => '', 'disabled' => 1],
            ['id' => 4, 'firstname' => 'Me', 'lastname' => 'Myself', 'email' => 'ME@example.com', 'roleid' => 1, 'password' => '', 'disabled' => 0],
        ]);
        $this->whmcs->table('tbltickets')->where('id', 60)->update(['flag' => 1]);
        $me = User::factory()->create(['email' => 'me@example.com', 'name' => 'Me Here']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $jane = User::where('email', 'jane@example.com')->sole();
        $this->assertSame([StaffRole::Support, 'Jane Admin'], [$jane->role, $jane->name]);
        $this->assertSame(StaffRole::Billing, User::where('email', 'gone@example.com')->value('role'));
        $this->assertFalse(User::where('email', 'old@example.com')->exists());
        $this->assertSame([$me->id, 'Me Here'], [User::where('email', 'me@example.com')->value('id'), $me->fresh()->name]);
        $this->assertSame(3, User::count());

        // WHMCS's bcrypt hash works as it is.
        $this->post(route('admin.login'), ['email' => 'jane@example.com', 'password' => 'whmcs-pass'])->assertRedirect(route('admin.dashboard'));

        $ticket = Ticket::find(60);
        $this->assertSame($jane->id, $ticket->assigned_to);
        $reply = $ticket->messages()->whereNull('client_id')->sole();
        $this->assertSame([$jane->id, 'Jane Admin'], [$reply->user_id, $reply->authorName()]);
        $this->assertSame($jane->id, EscalationRule::sole()->assign_to);

        $markdown = $report->toMarkdown();
        $this->assertStringContainsString('Old Timer is disabled in WHMCS', $markdown);
        $this->assertStringContainsString('1 admin(s) had a password WHMCS kept in an older form', $markdown);
        $this->assertStringContainsString('Jane Admin (Support Operator in WHMCS) has the Support role here.', $markdown);
    }

    public function test_feedback_ratings_come_over_out_of_five(): void
    {
        $this->whmcs->table('tbltickets')->where('id', 60)->update(['status' => 'Closed']);
        $this->whmcs->table('tblticketfeedback')->insert([
            ['ticketid' => 60, 'adminid' => 1, 'rating' => 9, 'comments' => 'Fast', 'datetime' => '2026-10-02 09:00:00'],
            ['ticketid' => 60, 'adminid' => 2, 'rating' => 6, 'comments' => '', 'datetime' => '2026-10-02 09:00:00'],
            ['ticketid' => 999, 'adminid' => 1, 'rating' => 10, 'comments' => '', 'datetime' => '2026-10-02 09:00:00'],
        ]);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $feedback = Ticket::find(60)->feedback;
        // (9 + 6) / 2 = 7.5 out of 10, which is 4 out of 5 rounded up.
        $this->assertSame([4, 'Fast', 7], [$feedback->rating, $feedback->comment, $feedback->client_id]);
        $this->assertSame('2026-10-02', $feedback->created_at->toDateString());
        $this->assertSame(['found' => 2, 'imported' => 1, 'skipped' => 1], $report->counts()['Ticket feedback']);
        $this->assertStringContainsString('had a rating for each admin who replied', $report->toMarkdown());
    }

    public function test_tickets_from_people_without_an_account_come_over_as_guest_tickets(): void
    {
        $this->whmcs->table('tbltickets')->insert([
            ['id' => 62, 'tid' => '990001', 'did' => 2, 'userid' => 0, 'name' => 'Sam Visitor', 'email' => 'Sam@Visitor.example', 'date' => '2026-10-03 10:00:00', 'title' => 'Pre-sales', 'message' => 'Do you do VPS?', 'status' => 'Answered', 'urgency' => 'Low', 'admin' => '', 'lastreply' => '2026-10-03 11:00:00', 'merged_ticket_id' => 0],
            ['id' => 63, 'tid' => '990002', 'did' => 2, 'userid' => 0, 'name' => 'Nobody', 'email' => '', 'date' => '2026-10-03 10:00:00', 'title' => 'No address', 'message' => '?', 'status' => 'Open', 'urgency' => 'Low', 'admin' => '', 'lastreply' => null, 'merged_ticket_id' => 0],
        ]);
        $this->whmcs->table('tblticketreplies')->insert(['tid' => 62, 'userid' => 0, 'name' => 'Sam Visitor', 'date' => '2026-10-03 12:00:00', 'message' => 'Thanks!', 'admin' => '']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $ticket = Ticket::find(62);
        $this->assertTrue($ticket->isGuest());
        $this->assertSame(['Sam Visitor', 'sam@visitor.example'], [$ticket->guest_name, $ticket->guest_email]);
        $this->assertNotNull($ticket->access_key);
        $this->assertSame([true, true], $ticket->messages->pluck('guest')->all());
        $this->assertNull(Ticket::find(63));
        $this->assertStringContainsString('without a client account or a valid email address', $report->toMarkdown());
        $this->assertStringContainsString('1 ticket(s) came from people without a client account', $report->toMarkdown());
    }

    public function test_translations_of_articles_and_announcements_come_over_and_their_languages_are_offered(): void
    {
        $this->whmcs->table('tblknowledgebase')->insert(['id' => 4, 'title' => 'Mot de passe cPanel', 'article' => '<p>En français</p>', 'parentid' => 1, 'language' => 'french']);
        $this->whmcs->table('tblknowledgebase')->insert(['id' => 5, 'title' => 'Klingon', 'article' => '<p>?</p>', 'parentid' => 1, 'language' => 'klingon!']);
        $this->whmcs->table('tblknowledgebasetags')->insert(['articleid' => 4, 'tag' => 'mot de passe']);
        $this->whmcs->table('tblconfiguration')->insert(['setting' => 'Language', 'value' => 'english']);

        $report = (new WhmcsImporter)->run($this->whmcs, dryRun: false);

        $article = KbArticle::where('title', 'Reset your cPanel password')->sole();
        $this->assertSame(['de', 'fr'], $article->translations->pluck('locale')->all());
        $french = $article->translations->firstWhere('locale', 'fr');
        $this->assertSame(['Mot de passe cPanel', 'En français', 'mot de passe'], [$french->title, $french->body, $french->tags]);
        $this->assertSame('Neues Rechenzentrum', Announcement::where('title', 'New data centre')->sole()->translations->sole()->title);
        $this->assertSame(['en', ['de', 'fr']], [config('help.main_language'), config('help.languages')]);
        $this->assertSame(['found' => 4, 'imported' => 3, 'skipped' => 1], $report->counts()['Translations']);

        $this->get(route('kb.article', $article), ['Accept-Language' => 'de'])->assertOk()->assertSee('cPanel Passwort');
    }

    public function test_a_dry_run_leaves_the_language_settings_alone(): void
    {
        (new WhmcsImporter)->run($this->whmcs, dryRun: true);

        $this->assertSame([], config('help.languages'));
        $this->assertSame(0, Setting::count());
    }

    public function test_the_wizard_takes_the_downloads_folder(): void
    {
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(), 'web');

        $this->post(route('admin.whmcs-import.connect'), ['source' => 'env', 'downloads' => '/no/such/folder'])
            ->assertSessionHasErrors('whmcs');

        $this->post(route('admin.whmcs-import.connect'), ['source' => 'env', 'downloads' => $this->downloads])->assertSessionHasNoErrors();
        $this->post(route('admin.whmcs-import.dry-run'))->assertSessionHasNoErrors();
        $this->get(route('admin.whmcs-import.show', ['step' => 3]))->assertOk()->assertSee('Knowledge base articles')->assertSee('Escalation rules');
        @unlink((string) session('whmcs_import_wizard.dry_run.path'));
    }

    public function test_html_becomes_markdown(): void
    {
        $this->assertSame(
            "## Set up & go\n\nLog in,\\\nthen [click](https://example.com/?a=1&b=2).\n\n1. One\n1. Two\n\n```\n<dir>\n```\n\nbad",
            HtmlToMarkdown::convert("<h2>Set up &amp; go</h2>\n    <p>Log in,<br>then <a href=\"https://example.com/?a=1&amp;b=2\">click</a>.</p><ol><li>One</li><li>Two</li></ol><pre>&lt;dir&gt;</pre><a href=\"javascript:alert(1)\">bad</a>"),
        );
    }
}
