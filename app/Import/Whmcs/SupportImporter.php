<?php

namespace App\Import\Whmcs;

use App\Enums\TicketPriority;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\NetworkIssue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The support content around tickets: the knowledge base, announcements
 * (both with their translations), network issues, downloads (with their
 * files, from a copy of WHMCS's downloads folder) and ticket escalation
 * rules.
 */
class SupportImporter
{
    private const KB_CATEGORIES = 'Knowledge base categories';

    private const KB_ARTICLES = 'Knowledge base articles';

    private const ANNOUNCEMENTS = 'Announcements';

    private const NETWORK_ISSUES = 'Network issues';

    private const DOWNLOAD_CATEGORIES = 'Download categories';

    private const DOWNLOADS = 'Downloads';

    private const ESCALATIONS = 'Escalation rules';

    /** What an article or announcement with no text gets, since the body is required here. */
    public const NO_TEXT = '(No text.)';

    /** @var list<string> download files copied in, removed again if the import fails */
    private array $copied = [];

    private TranslationImporter $translations;

    /**
     * @param  array<int, int>  $departments  WHMCS department id => local department id
     * @param  array<int, array{id: int, onetime: bool, module: ?string}>  $products  WHMCS product id => local product
     */
    public function __construct(
        private ImportRun $run,
        private ServerImporter $servers,
        private StaffImporter $staff,
        private array $departments,
        private array $products,
        private ?string $downloadsPath = null,
    ) {
        $this->translations = new TranslationImporter($run);
    }

    public function import(): void
    {
        $this->importKnowledgebase();
        $this->importAnnouncements();
        $this->importNetworkIssues();
        $this->importDownloads();
        $this->importEscalations();
        $this->translations->offerLanguages();
    }

    public function removeCopiedFiles(): void
    {
        foreach ($this->copied as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function importKnowledgebase(): void
    {
        if (! $this->run->hasTables('tblknowledgebasecats', 'tblknowledgebase', 'tblknowledgebaselinks')) {
            return;
        }

        $categories = $this->importKbCategories();
        $links = $this->run->table('tblknowledgebaselinks')->orderBy('id')->get()->groupBy(fn ($link) => (int) $link->articleid);
        $tags = $this->run->hasTables('tblknowledgebasetags')
            ? $this->run->table('tblknowledgebasetags')->orderBy('id')->get()->groupBy(fn ($tag) => (int) $tag->articleid)
            : collect();

        $articles = [];
        $translations = [];

        foreach ($this->run->table('tblknowledgebase')->orderBy('id')->cursor() as $row) {
            if (! TranslationImporter::original($row, 'parentid')) {
                $translations[] = $row;

                continue;
            }

            $categoryIds = $links->get((int) $row->id, collect())->map(fn ($link) => $categories[(int) $link->categoryid] ?? null)->filter()->values();
            $id = $this->importKbArticle($row, $categoryIds, $tags->get((int) $row->id, collect()));

            if ($id !== null) {
                $articles[(int) $row->id] = $id;
            }
        }

        foreach ($translations as $row) {
            $this->translations->import(KbArticle::class, $articles, $row, 'title', 'article', $tags->get((int) $row->id, collect()));
        }
    }

    /**
     * Categories go one level deep here, so deeper ones move up under their top-level category.
     *
     * @return array<int, int> new category ids by WHMCS id
     */
    private function importKbCategories(): array
    {
        [$rows, $translations] = $this->run->table('tblknowledgebasecats')->orderBy('id')->get()->partition(fn ($row) => TranslationImporter::original($row, 'catid'));
        $byId = $rows->keyBy(fn ($row) => (int) $row->id);
        $categories = [];

        // Parents before children.
        foreach ($rows->sortBy(fn ($row) => [$this->topParent($byId, (int) $row->parentid) === null ? 0 : 1, (int) $row->id]) as $row) {
            $this->run->report->found(self::KB_CATEGORIES);
            $parent = $this->topParent($byId, (int) $row->parentid);

            if ($parent !== null && (int) $row->parentid !== $parent) {
                $this->run->report->note(self::KB_CATEGORIES, '"'.Whmcs::text($row->name).'" was nested more than one level deep, so it moved up under "'.Whmcs::text($byId[$parent]->name).'".');
            }

            $categories[(int) $row->id] = DB::table('kb_categories')->insertGetId([
                'parent_id' => $parent === null ? null : ($categories[$parent] ?? null),
                'name' => Whmcs::short($row->name) ?? 'Untitled',
                'description' => Whmcs::text($row->description ?? '') ?: null,
                'visible' => ! Whmcs::on($row->hidden ?? ''),
                'sort_order' => 0,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::KB_CATEGORIES);
        }

        foreach ($translations as $row) {
            $this->translations->importCategory($categories, $row);
        }

        return $categories;
    }

    /**
     * @param  Collection<int, int>  $categoryIds
     * @param  Collection<int, object>  $tags
     */
    private function importKbArticle(object $row, Collection $categoryIds, Collection $tags): ?int
    {
        $this->run->report->found(self::KB_ARTICLES);
        $title = Whmcs::text($row->title);

        if ($categoryIds->isEmpty()) {
            $this->run->report->skipped(self::KB_ARTICLES, "\"{$title}\" isn't in a category that was imported.");

            return null;
        }

        if ($categoryIds->count() > 1) {
            $this->run->report->note(self::KB_ARTICLES, "\"{$title}\" was in {$categoryIds->count()} categories; here it's in the first one.");
        }

        $private = Whmcs::on($row->private ?? '');
        if ($private) {
            $this->run->report->note(self::KB_ARTICLES, "\"{$title}\" was only for logged-in clients in WHMCS. Articles here are public, so it was imported unpublished; check it before publishing.");
        }

        $votes = (int) ($row->votes ?? 0);
        $useful = min((int) ($row->useful ?? 0), $votes);

        $id = DB::table('kb_articles')->insertGetId([
            'kb_category_id' => $categoryIds->first(),
            'title' => Str::limit($title, 250, '') ?: 'Untitled',
            'body' => HtmlToMarkdown::convert($row->article ?? '') ?: self::NO_TEXT,
            'tags' => Str::limit($tags->map(fn ($tag) => Whmcs::text($tag->tag))->filter()->unique()->join(', '), 250, '') ?: null,
            'published' => ! $private,
            'sort_order' => (int) ($row->order ?? 0),
            'views' => (int) ($row->views ?? 0),
            'helpful' => $useful,
            'not_helpful' => $votes - $useful,
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ]);
        $this->run->report->imported(self::KB_ARTICLES);

        return $id;
    }

    private function importAnnouncements(): void
    {
        if (! $this->run->hasTables('tblannouncements')) {
            return;
        }

        $announcements = [];
        $translations = [];

        foreach ($this->run->table('tblannouncements')->orderBy('id')->cursor() as $row) {
            if (! TranslationImporter::original($row, 'parentid')) {
                $translations[] = $row;

                continue;
            }

            $this->run->report->found(self::ANNOUNCEMENTS);
            $date = Whmcs::dateTime($row->date ?? null) ?? $this->run->now;

            $announcements[(int) $row->id] = DB::table('announcements')->insertGetId([
                'title' => Whmcs::short($row->title) ?? 'Untitled',
                'body' => HtmlToMarkdown::convert($row->announcement ?? '') ?: self::NO_TEXT,
                'published' => Whmcs::on($row->published ?? ''),
                'published_at' => $date,
                'created_at' => $date,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::ANNOUNCEMENTS);
        }

        foreach ($translations as $row) {
            $this->translations->import(Announcement::class, $announcements, $row, 'title', 'announcement');
        }
    }

    private function importNetworkIssues(): void
    {
        if (! $this->run->hasTables('tblnetworkissues')) {
            return;
        }

        foreach ($this->run->table('tblnetworkissues')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::NETWORK_ISSUES);
            $title = Whmcs::short($row->title) ?? 'Untitled';
            $server = (int) ($row->server ?? 0) > 0 ? $this->servers->serverId($row->server) : null;

            if ((int) ($row->server ?? 0) > 0 && $server === null) {
                $this->run->report->note(self::NETWORK_ISSUES, "\"{$title}\" was about server {$row->server}, which wasn't imported, so it shows what it affects in words.");
            }

            $status = self::networkStatus($row->status ?? '');
            $starts = Whmcs::dateTime($row->startdate ?? null) ?? $this->run->now;
            $ends = Whmcs::dateTime($row->enddate ?? null);

            DB::table('network_issues')->insert([
                'title' => $title,
                'description' => HtmlToMarkdown::convert($row->description ?? '') ?: $title,
                'server_id' => $server,
                'affecting' => $server === null ? (Whmcs::short($row->affecting ?? '') ?? Whmcs::short($row->type ?? '')) : null,
                'priority' => self::networkPriority($row->priority ?? ''),
                'status' => $status,
                'starts_at' => $starts,
                // A resolved issue needs an end; WHMCS's last update is when it was resolved.
                'ends_at' => $ends ?? ($status === NetworkIssue::RESOLVED ? (Whmcs::dateTime($row->lastupdate ?? null) ?? $starts) : null),
                'created_at' => $starts,
                'updated_at' => Whmcs::dateTime($row->lastupdate ?? null) ?? $this->run->now,
            ]);
            $this->run->report->imported(self::NETWORK_ISSUES);
        }
    }

    private function importDownloads(): void
    {
        if (! $this->run->hasTables('tbldownloadcats', 'tbldownloads')) {
            return;
        }

        $categories = [];
        $rows = $this->run->table('tbldownloadcats')->orderBy('id')->get()->keyBy(fn ($row) => (int) $row->id);

        foreach ($rows as $row) {
            $this->run->report->found(self::DOWNLOAD_CATEGORIES);

            if (self::hiddenCategory($row, $rows)) {
                $this->run->report->skipped(self::DOWNLOAD_CATEGORIES, '"'.Whmcs::text($row->name).'" is hidden in WHMCS, or sits under a hidden category, so it and its downloads were left out.');

                continue;
            }

            if ((int) ($row->parentid ?? 0) > 0) {
                $this->run->report->note(self::DOWNLOAD_CATEGORIES, '"'.Whmcs::text($row->name).'" was a subcategory in WHMCS; here download categories are one level, so it\'s a category of its own.');
            }

            $categories[(int) $row->id] = DB::table('download_categories')->insertGetId([
                'name' => Whmcs::short($row->name) ?? 'Untitled',
                'description' => Whmcs::text($row->description ?? '') ?: null,
                'sort_order' => 0,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::DOWNLOAD_CATEGORIES);
        }

        $products = $this->run->hasTables('tblproduct_downloads')
            ? $this->run->table('tblproduct_downloads')->get()->groupBy(fn ($link) => (int) $link->download_id)
            : collect();

        foreach ($this->run->table('tbldownloads')->orderBy('id')->cursor() as $row) {
            $this->importDownload($row, $categories, $products->get((int) $row->id, collect()));
        }
    }

    /**
     * Whether a download category or any category above it is hidden in WHMCS.
     *
     * @param  Collection<int, object>  $rows
     */
    private static function hiddenCategory(object $row, Collection $rows): bool
    {
        $seen = [];

        while ($row !== null && ! isset($seen[(int) $row->id])) {
            if (Whmcs::on($row->hidden ?? '')) {
                return true;
            }
            $seen[(int) $row->id] = true;
            $row = $rows->get((int) ($row->parentid ?? 0));
        }

        return false;
    }

    /**
     * @param  array<int, int>  $categories
     * @param  Collection<int, object>  $productLinks
     */
    private function importDownload(object $row, array $categories, $productLinks): void
    {
        $this->run->report->found(self::DOWNLOADS);
        $title = Whmcs::text($row->title);
        $location = trim((string) ($row->location ?? ''));
        $file = $this->downloadsPath !== null ? rtrim($this->downloadsPath, '/').'/'.basename($location) : null;

        $problem = match (true) {
            Whmcs::on($row->hidden ?? '') => 'is hidden in WHMCS',
            ! isset($categories[(int) $row->category]) => 'is in a category that wasn\'t imported',
            (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) => "links to {$location} rather than a file, so add that link to an announcement or knowledge base article instead",
            $file === null => "({$location}) needs the WHMCS downloads folder: give it when connecting to bring files over",
            ! is_file($file) => "({$location}) is missing from {$this->downloadsPath}",
            default => null,
        };

        if ($problem !== null) {
            $this->run->report->skipped(self::DOWNLOADS, "\"{$title}\" {$problem}.");

            return;
        }

        $path = 'downloads/'.Str::random(40);

        if (! $this->run->report->dryRun) {
            Storage::disk('local')->put($path, (string) file_get_contents($file));
            $this->copied[] = $path;
        }

        $id = DB::table('downloads')->insertGetId([
            'download_category_id' => $categories[(int) $row->category],
            'title' => Str::limit($title, 250, '') ?: basename($location),
            'description' => Whmcs::plain((string) ($row->description ?? '')) ?: null,
            'path' => $path,
            'file_name' => Str::limit(basename($location), 250, ''),
            'mime' => mime_content_type($file) ?: null,
            'size' => (int) filesize($file),
            'clients_only' => Whmcs::on($row->clientsonly ?? ''),
            'downloads' => (int) ($row->downloads ?? 0),
            'sort_order' => 0,
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ]);

        if (Whmcs::on($row->productdownload ?? '')) {
            $productIds = $productLinks->map(fn ($link) => $this->products[(int) $link->product_id]['id'] ?? null)->filter()->unique()->values();

            foreach ($productIds as $productId) {
                DB::table('download_product')->insert(['download_id' => $id, 'product_id' => $productId]);
            }

            if ($productIds->isEmpty()) {
                $this->run->report->note(self::DOWNLOADS, "\"{$title}\" was a product download, but none of its products were imported, so it's for every logged-in client.");
                DB::table('downloads')->where('id', $id)->update(['clients_only' => true]);
            }
        }

        $this->run->report->imported(self::DOWNLOADS);
    }

    private function importEscalations(): void
    {
        if (! $this->run->hasTables('tblticketescalations')) {
            return;
        }

        $staff = fn (mixed $adminId): ?int => $this->staff->userId($adminId);

        foreach ($this->run->table('tblticketescalations')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::ESCALATIONS);
            $name = Whmcs::short($row->name) ?? "Rule {$row->id}";
            $notes = [];

            DB::table('escalation_rules')->insert([
                'name' => $name,
                // Off until staff check it: rules act on live tickets and email clients.
                'active' => false,
                'sort_order' => (int) $row->id,
                ...$this->escalationConditions($row, $notes),
                ...$this->escalationActions($row, $staff, $notes),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            $this->run->report->note(self::ESCALATIONS, "\"{$name}\" was imported turned off, so you can check it before it acts on tickets".($notes === [] ? '.' : ': '.implode('; ', $notes).'.'));
            $this->run->report->imported(self::ESCALATIONS);
        }
    }

    /**
     * Which tickets a WHMCS escalation rule applies to, as escalation_rules columns.
     *
     * @param  list<string>  $notes  what didn't come over
     * @return array<string, mixed>
     */
    private function escalationConditions(object $row, array &$notes): array
    {
        $departments = collect(self::list($row->departments ?? ''))->map(fn ($id) => $this->departments[(int) $id] ?? null)->filter()->unique()->values();
        $statuses = collect(self::list($row->statuses ?? ''))->map(function (string $status) use (&$notes) {
            $mapped = self::knownStatus($status);
            if ($mapped === null) {
                $notes[] = "its status \"{$status}\" doesn't exist here and was left out";
            }

            return $mapped;
        })->filter()->unique()->values();
        $priorities = collect(self::list($row->priorities ?? ''))->map(fn ($priority) => Whmcs::priority($priority)->value)->unique()->values();
        $allPriorities = $priorities->isEmpty() || $priorities->count() === count(TicketPriority::cases());
        $minutes = (int) ($row->timeelapsed ?? 0);

        return [
            'department_ids' => $departments->isEmpty() ? null : json_encode($departments->all()),
            'statuses' => $statuses->isEmpty() ? null : json_encode($statuses->all()),
            'priorities' => $allPriorities ? null : json_encode($priorities->all()),
            'minutes_since_reply' => $minutes > 0 ? $minutes : null,
            'sla_breached' => false,
        ];
    }

    /**
     * What a WHMCS escalation rule does, as escalation_rules columns.
     *
     * @param  callable(mixed): ?int  $staff  the staff account for a WHMCS admin id
     * @param  list<string>  $notes  what didn't come over
     * @return array<string, mixed>
     */
    private function escalationActions(object $row, callable $staff, array &$notes): array
    {
        $flagTo = (int) ($row->flagto ?? 0);
        $assignTo = $flagTo > 0 ? $staff($flagTo) : null;
        if ($flagTo > 0 && $assignTo === null) {
            $notes[] = "it assigned tickets to WHMCS admin {$flagTo}, who wasn't imported (disabled, or no email address)";
        }

        $notify = self::list($row->notify ?? '');
        $notifyWords = array_map('strtolower', $notify);
        $notifyUsers = collect($notify)->filter(fn ($value) => ctype_digit($value))->map(fn ($id) => $staff($id))->filter()->unique()->values();
        if (array_intersect($notifyWords, ['all', 'department', 'dept']) !== []) {
            $notes[] = 'it emailed all or the department\'s admins; pick who to email on the rule';
        }

        $newStatus = filled($row->newstatus ?? null) ? self::knownStatus((string) $row->newstatus) : null;
        if (filled($row->newstatus ?? null) && $newStatus === null) {
            $notes[] = "it set the status \"{$row->newstatus}\", which doesn't exist here";
        }

        return [
            'set_department_id' => $this->departments[(int) ($row->newdepartment ?? 0)] ?? null,
            'set_status' => $newStatus,
            'set_priority' => filled($row->newpriority ?? null) ? Whmcs::priority($row->newpriority)->value : null,
            'assign_to' => $assignTo,
            'notify_assignee' => array_intersect($notifyWords, ['assigned', 'flagged', 'flag']) !== [],
            'notify_user_ids' => $notifyUsers->isEmpty() ? null : json_encode($notifyUsers->all()),
            'reply' => filled($row->addreply ?? null) ? Whmcs::plain((string) $row->addreply) : null,
        ];
    }

    /**
     * The top-level ancestor of a category, or the category itself when it is
     * top level or its parent is: null for a top-level category.
     *
     * @param  Collection<int, object>  $byId
     */
    private function topParent($byId, int $parentId): ?int
    {
        $seen = [];

        while ($parentId > 0 && $byId->has($parentId) && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $grandparent = (int) $byId[$parentId]->parentid;

            if ($grandparent === 0 || ! $byId->has($grandparent)) {
                return $parentId;
            }

            $parentId = $grandparent;
        }

        return null;
    }

    /** @return list<string> "1,2,,3" -> ["1", "2", "3"] */
    private static function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $item) => $item !== ''));
    }

    private static function networkPriority(mixed $value): string
    {
        $priority = strtolower(trim((string) $value));

        return array_key_exists($priority, NetworkIssue::PRIORITIES) ? $priority : 'medium';
    }

    private static function networkStatus(mixed $value): string
    {
        $status = str_replace([' ', '-'], '_', strtolower(trim((string) $value)));

        return array_key_exists($status, NetworkIssue::STATUSES) ? $status : 'investigating';
    }

    /** A WHMCS ticket status name as a status here, or null for custom WHMCS statuses. */
    private static function knownStatus(string $value): ?string
    {
        $key = strtolower(str_replace([' ', '-'], '', $value));

        return in_array($key, ['open', 'answered', 'customerreply', 'onhold', 'closed'], true) ? Whmcs::ticketStatus($value)->value : null;
    }
}
