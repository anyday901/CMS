<?php

return [
    /*
     * Largest file staff can upload to the downloads area, in MB. PHP's
     * upload_max_filesize and post_max_size must allow it too.
     */
    'download_max_mb' => (int) env('DOWNLOADS_MAX_MB', 8),
];
