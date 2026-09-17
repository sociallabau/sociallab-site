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
    'lead_recipients' => ['digital@sociallab.com.au'],

    // Link emailed/handed out after a rate card request.
    'rate_card_url'   => 'https://social-lab-rate-card.lovable.app/',

    // Growth Hub webhook — set in config.local.php once the endpoint exists.
    'growth_hub_url'    => '',
    'growth_hub_secret' => '',
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
