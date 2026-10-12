<?php

namespace App\Import\Whmcs;

/** What an import found, brought over and skipped, and how the totals compare. */
class ImportReport
{
    /** @var array<string, array{found: int, imported: int, skipped: int}> */
    private array $counts = [];

    /** @var array<string, list<string>> */
    private array $notes = [];

    /** @var list<array{label: string, whmcs: string, here: string}> */
    private array $checks = [];

    public function __construct(public readonly bool $dryRun) {}

    public function found(string $section, int $count = 1): void
    {
        $this->bump($section, 'found', $count);
    }

    public function imported(string $section, int $count = 1): void
    {
        $this->bump($section, 'imported', $count);
    }

    public function skipped(string $section, string $reason): void
    {
        $this->bump($section, 'skipped', 1);
        $this->note($section, $reason);
    }

    /** Something brought over with a change worth knowing about. */
    public function note(string $section, string $message): void
    {
        $this->notes[$section][] = $message;
    }

    public function check(string $label, string $whmcs, string $here): void
    {
        $this->checks[] = ['label' => $label, 'whmcs' => $whmcs, 'here' => $here];
    }

    /** @return array<string, array{found: int, imported: int, skipped: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return list<array{label: string, whmcs: string, here: string}> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return list<array{label: string, whmcs: string, here: string}> */
    public function mismatches(): array
    {
        return array_values(array_filter($this->checks, fn (array $check) => $check['whmcs'] !== $check['here']));
    }

    /** @return array<string, list<string>> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function toMarkdown(): string
    {
        $lines = [
            '# WHMCS import '.($this->dryRun ? 'dry run' : 'report'),
            '',
            'Run '.now()->toDateTimeString().'. '.($this->dryRun
                ? 'Nothing was saved: this shows what a real import would do.'
                : 'Everything below has been saved.'),
            '',
            '## Records',
            '',
            '| Records | In WHMCS | Imported | Skipped |',
            '| --- | ---: | ---: | ---: |',
        ];

        foreach ($this->counts as $section => $count) {
            $lines[] = "| {$section} | {$count['found']} | {$count['imported']} | {$count['skipped']} |";
        }

        $lines = [...$lines, '', '## Totals compared', '', '| Total | WHMCS | Here | Match |', '| --- | ---: | ---: | --- |'];

        foreach ($this->checks as $check) {
            $match = $check['whmcs'] === $check['here'] ? 'yes' : '**no**';
            $lines[] = "| {$check['label']} | {$check['whmcs']} | {$check['here']} | {$match} |";
        }

        if ($this->notes !== []) {
            $lines = [...$lines, '', '## Notes', ''];
        }

        foreach ($this->notes as $section => $notes) {
            $lines[] = "### {$section}";
            $lines[] = '';

            foreach ($notes as $note) {
                $lines[] = "- {$note}";
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** Writes the Markdown report, by default to storage/app/whmcs/, and returns its path. */
    public function save(?string $path = null): string
    {
        $prefix = $this->dryRun ? 'import-dry-run-' : 'import-';
        $path = $path ?: storage_path('app/whmcs/'.$prefix.now()->format('Ymd-His').'.md');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->toMarkdown());

        return $path;
    }

    private function bump(string $section, string $key, int $count): void
    {
        $this->counts[$section] ??= ['found' => 0, 'imported' => 0, 'skipped' => 0];
        $this->counts[$section][$key] += $count;
    }
}
