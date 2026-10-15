<?php

namespace App\Reports;

/** What a report gives: one or more tables, and notes on how to read them. */
class ReportResult
{
    /**
     * @param  list<ReportTable>  $tables
     * @param  list<string>  $notes
     */
    public function __construct(public array $tables = [], public array $notes = []) {}

    public function table(ReportTable $table): static
    {
        $this->tables[] = $table;

        return $this;
    }

    public function note(string $note): static
    {
        $this->notes[] = $note;

        return $this;
    }
}
