<?php

return [
    /*
     * Shared secret for POST /webhooks/email, which takes piped email over
     * HTTP. Leave blank to turn the endpoint off; the tickets:pipe command
     * works either way.
     */
    'inbound_secret' => env('TICKETS_INBOUND_SECRET'),

    /*
     * Close answered tickets the client hasn't replied to in this many hours.
     * Blank turns it off. Can be changed under Settings.
     */
    'close_inactive_hours' => env('TICKETS_CLOSE_INACTIVE_HOURS'),

    /*
     * Opening hours, for departments whose response time targets count
     * business hours only. Days left out are closed. Times are in the
     * timezone below. All three can be changed under Settings.
     */
    'business_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], ['09:00', '17:00']),

    // Dates (YYYY-MM-DD) the business is closed all day, such as public holidays.
    'holidays' => [],

    'timezone' => env('TICKETS_TIMEZONE', 'UTC'),
];
