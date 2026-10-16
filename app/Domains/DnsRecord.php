<?php

namespace App\Domains;

/** One DNS record: "www" CNAME "example.com.", "@" MX "mail.example.com" priority 10. */
final readonly class DnsRecord
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    public function __construct(
        public string $host,
        public string $type,
        public string $value,
        public ?int $priority = null,
        public int $ttl = 3600,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): self
    {
        $host = trim((string) ($row['host'] ?? ''));
        $type = strtoupper(trim((string) ($row['type'] ?? '')));

        return new self(
            $host === '' ? '@' : $host,
            $type,
            trim((string) ($row['value'] ?? '')),
            $type === 'MX' ? (int) ($row['priority'] ?? 10) : null,
            max(60, (int) ($row['ttl'] ?? 3600)),
        );
    }

    /** @return array{host: string, type: string, value: string, priority: ?int, ttl: int} */
    public function toArray(): array
    {
        return ['host' => $this->host, 'type' => $this->type, 'value' => $this->value, 'priority' => $this->priority, 'ttl' => $this->ttl];
    }

    /** The full name the record answers for: "www.example.com", or the domain itself for "@". */
    public function fqdn(string $domain): string
    {
        return $this->host === '@' ? $domain : "{$this->host}.{$domain}";
    }
}
