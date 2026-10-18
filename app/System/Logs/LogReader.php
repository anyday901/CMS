<?php

namespace App\System\Logs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reads the Laravel log files for Settings > Logs. Only plain .log files
 * directly in the log folder are listed or read, and only the end of a
 * big file is read.
 */
class LogReader
{
    /** File names it will list or read: no slashes, so no other folder. */
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.log$/';

    /** The start of a Laravel (Monolog) log entry: [time] environment.LEVEL: message */
    private const ENTRY = '/^\[(\d{4}-\d{2}-\d{2}[T ][0-9:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w.-]+)\.([A-Z]+): (.*)$/';

    /** Monolog's levels, most serious first. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function path(): string
    {
        return (string) config('logging.viewer.path');
    }

    public function maxBytes(): int
    {
        return max(1024, (int) config('logging.viewer.max_bytes'));
    }

    /** @return Collection<int, array{name: string, size: int, modified_at: Carbon}> newest first */
    public function files(): Collection
    {
        if (! is_dir($this->path())) {
            return collect();
        }

        return collect(scandir($this->path()) ?: [])
            ->filter(fn (string $name) => preg_match(self::NAME, $name) && is_file($this->path().'/'.$name))
            ->map(fn (string $name) => [
                'name' => $name,
                'size' => (int) filesize($this->path().'/'.$name),
                'modified_at' => Carbon::createFromTimestamp((int) filemtime($this->path().'/'.$name)),
            ])
            ->sortByDesc(fn (array $file) => $file['modified_at']->getTimestamp())
            ->values();
    }

    /** The full path of a log file, after checking it is one in the log folder. */
    public function file(string $name): string
    {
        $file = $this->path().'/'.$name;
        $real = realpath($file);

        if (! preg_match(self::NAME, $name) || ! is_file($file) || $real === false || dirname($real) !== realpath($this->path())) {
            throw new InvalidArgumentException('There is no log file called '.$name.'.');
        }

        return $file;
    }

    /**
     * The entries of a log file, newest first, read from at most the last
     * max bytes of it.
     *
     * @return array{entries: list<array{time: ?string, environment: ?string, level: string, message: string, details: string}>, truncated: bool}
     */
    public function read(string $name): array
    {
        $file = $this->file($name);
        $size = (int) filesize($file);
        $truncated = $size > $this->maxBytes();

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Can\'t read '.$name.'.');
        }
        if ($truncated) {
            fseek($handle, $size - $this->maxBytes());
            fgets($handle); // The first line is probably cut in half.
        }
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return ['entries' => array_reverse($this->parse($text, $truncated)), 'truncated' => $truncated];
    }

    /** @return list<array{time: ?string, environment: ?string, level: string, message: string, details: string}> oldest first */
    private function parse(string $text, bool $truncated): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match(self::ENTRY, $line, $match)) {
                if ($current !== null) {
                    $entries[] = $this->finish($current);
                }
                $current = ['time' => $match[1], 'environment' => $match[2], 'level' => strtolower($match[3]), 'message' => $match[4], 'details' => ''];
            } elseif ($current !== null) {
                $current['details'] .= $line."\n";
            } elseif (! $truncated && trim($line) !== '') {
                // Text before the first entry of a whole file, written by something else.
                $current = ['time' => null, 'environment' => null, 'level' => 'unknown', 'message' => $line, 'details' => ''];
            }
        }

        if ($current !== null) {
            $entries[] = $this->finish($current);
        }

        return $entries;
    }

    private function finish(array $entry): array
    {
        $entry['details'] = rtrim($entry['details']);

        return $entry;
    }
}
