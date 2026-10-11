<?php

return [
    /*
     * Shared secret for POST /webhooks/email, which takes piped email over
     * HTTP. Leave blank to turn the endpoint off; the tickets:pipe command
     * works either way.
     */
    'inbound_secret' => env('TICKETS_INBOUND_SECRET'),
];
