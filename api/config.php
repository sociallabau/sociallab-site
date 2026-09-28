<?php
/**
 * Site-wide configuration.
 *
 * Secrets (SMTP password, Growth Hub key) live in config.local.php, which is
 * git-ignored and uploaded to SiteGround once by hand. See README.md.
 */

$config = [
    // ---------------------------------------------------------------- site
    'site_name'   => 'Social Lab',
    'site_url'    => 'https://sociallab.com.au',
    'tagline'     => 'Marketing for the property industry',

    // ------------------------------------------------------------ contact
    'email'       => 'digital@sociallab.com.au',
    'phone'       => '0459 224 408',
    'phone_link'  => '+61459224408',
    'instagram'   => 'https://www.instagram.com/sociallabau/',
    'team'        => [
        ['name' => 'Elijah Arnold', 'role' => 'Director',          'phone' => '0459 224 408', 'email' => 'digital@sociallab.com.au'],
        ['name' => 'Chloe Dolan',   'role' => 'Marketing Manager', 'phone' => '0477 002 951', 'email' => 'chloe@sociallab.com.au'],
    ],

    // ------------------------------------------------------- lead routing
    // Every enquiry is emailed to these addresses.
    'lead_recipients' => ['digital@sociallab.com.au', 'emily@sociallab.com.au'],

    // Sent through Gmail's own SMTP (not PHP's mail()), so it reliably shows as
    // coming from this address instead of failing Gmail's DMARC check. Needs an
    // app password in config.local.php — see api/config.local.example.php.
    'gmail_smtp_user'         => 'sociallabau@gmail.com',
    'gmail_smtp_app_password' => '',

    // Link emailed after a rate card request. Empty = the auto-reply promises
    // a follow-up instead, so no half-private link goes out by accident.
    'rate_card_url'   => '',

    // Growth Hub webhook. Left empty on purpose: the Lovable plan does not
    // allow environment secrets yet, so leads are emailed and added by hand.
    // Setting a URL here turns the integration back on; the endpoint this
    // expects is kept in docs/growth-hub-lead-endpoint.ts.txt.
    'growth_hub_url'    => '',
    'growth_hub_secret' => '',

    // ---------------------------------------------------------- meta pixel
    // Not a secret — a Pixel/Dataset ID is exposed client-side regardless.
    'meta_pixel_id'        => '156315858303152',
    // Conversions API access token (Events Manager -> Settings -> Conversions
    // API -> Generate access token). Left empty on purpose: CAPI is skipped
    // until this is set in config.local.php.
    'meta_capi_token'      => '',
    // Events Manager -> Test events tab. Leave empty outside of testing —
    // Meta excludes events tagged with a test code from real reporting.
    'meta_test_event_code' => '',
];

// Local overrides (never committed).
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $config = array_merge($config, $local);
    }
}

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Absolute URL for an asset or page, so includes work at any depth. */
function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

/** True when $section matches the page currently being rendered. */
function is_current(string $section): bool
{
    global $page;
    return ($page['section'] ?? '') === $section;
}
