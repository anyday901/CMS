<?php

namespace App\System;

use Illuminate\Support\Facades\File;

/** How the last update went, kept in a file so the updates page can show it while the queue worker runs it. */
class UpdateStatus
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function path(): string
    {
        return storage_path('app/updates/status.json');
    }

    /** @return array{state: string, version: string, step: string, message: string|null, started_at: string, finished_at: string|null}|null */
    public function read(): ?array
    {
        return is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;
    }

    public function running(): bool
    {
        $status = $this->read();

        // An update that has said nothing for an hour has died with its worker.
        return $status !== null && $status['state'] === self::RUNNING && now()->parse($status['updated_at'] ?? $status['started_at'])->gt(now()->subHour());
    }

    public function start(string $version): void
    {
        $this->write(['state' => self::RUNNING, 'version' => $version, 'step' => 'Starting', 'message' => null, 'started_at' => now()->toIso8601String(), 'finished_at' => null]);
    }

    public function step(string $step): void
    {
        $this->write(['step' => $step] + ($this->read() ?? []));
    }

    public function finish(string $message): void
    {
        $this->write(['state' => self::DONE, 'message' => $message, 'finished_at' => now()->toIso8601String()] + ($this->read() ?? []));
    }

    public function fail(string $message): void
    {
        $this->write(['state' => self::FAILED, 'message' => $message, 'finished_at' => now()->toIso8601String()] + ($this->read() ?? []));
    }

    private function write(array $status): void
    {
        File::ensureDirectoryExists(dirname($this->path()), 0700);
        file_put_contents($this->path(), json_encode(['updated_at' => now()->toIso8601String()] + $status, JSON_PRETTY_PRINT));
    }
}
