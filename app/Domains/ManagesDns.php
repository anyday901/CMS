<?php

namespace App\Domains;

use App\Models\Domain;

/** A registrar that hosts DNS records for its domains, which clients then edit in the portal. */
interface ManagesDns
{
    /** @return list<DnsRecord> */
    public function dnsRecords(Domain $domain, array $credentials): array;

    /**
     * Replaces the domain's records with these.
     *
     * @param  list<DnsRecord>  $records
     */
    public function setDnsRecords(Domain $domain, array $records, array $credentials): void;
}
