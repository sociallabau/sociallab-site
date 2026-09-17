<?php
/**
 * Copy this file to config.local.php on the server and fill in the real values.
 * config.local.php is git-ignored, so secrets never end up in the repository.
 */

return [
    // Where enquiries are emailed.
    'lead_recipients' => ['digital@sociallab.com.au', 'chloe@sociallab.com.au'],

    // Link sent out automatically when someone requests the rate card.
    'rate_card_url'   => 'https://sociallab.com.au/rate-card/',

    // Social Lab Growth Hub — the endpoint that should receive each lead.
    'growth_hub_url'    => '',
    'growth_hub_secret' => '',
];
