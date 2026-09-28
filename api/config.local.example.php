<?php
/**
 * Copy this file to config.local.php on the server and fill in the real values.
 * config.local.php is git-ignored, so secrets never end up in the repository.
 */

return [
    // Where enquiries are emailed.
    'lead_recipients' => ['digital@sociallab.com.au', 'chloe@sociallab.com.au', 'emily@sociallab.com.au'],

    // Link sent out automatically when someone requests the rate card.
    'rate_card_url'   => 'https://sociallab.com.au/rate-card/',

    // Growth Hub webhook — the social-lab-growth-hub deployment's own URL,
    // e.g. https://<its-domain>/api/website/lead. The secret must match
    // WEBSITE_LEAD_SECRET in that project's environment.
    'growth_hub_url'    => '',
    'growth_hub_secret' => '',

    // Gmail app password for sociallabau@gmail.com — myaccount.google.com/apppasswords
    // (needs 2-Step Verification turned on first). Without this set, lead
    // notifications fall back to the server's own mail().
    'gmail_smtp_app_password' => '',

    // Events Manager -> Settings -> Conversions API -> Generate access token.
    // Without this set, server-side CAPI events are skipped (the client-side
    // Pixel still fires either way).
    'meta_capi_token'      => '',
    // Events Manager -> Test events tab, while checking CAPI events arrive.
    // Leave empty in production — test-coded events don't count in reporting.
    'meta_test_event_code' => '',
];
