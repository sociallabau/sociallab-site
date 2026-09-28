<?php
/**
 * Enquiry handler.
 *
 * 1. Validates + filters the submission (honeypot, timing trap, simple rate limit).
 * 2. Emails the lead to the team.
 * 3. Posts the lead to the Growth Hub webhook, when one is configured.
 * 4. Sends an acknowledgement email to the person who enquired.
 *
 * Responds with JSON for the AJAX form, or redirects to /thank-you/ without JS.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gmail-smtp.php';

$wantsJson = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

/** Send the response in whichever format the client asked for. */
function respond(bool $ok, string $message, int $status = 200): never
{
    global $wantsJson;

    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }

    header('Location: ' . ($ok ? '/thank-you/' : '/contact/?error=1'), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}

// ----------------------------------------------------------------- spam ---

// Honeypot: real people never fill this in.
if (trim((string) ($_POST['company'] ?? '')) !== '') {
    respond(true, 'Thanks.');  // Silently accept so bots do not retry.
}

// Timing trap: the form JS reports how long the page was open before submitting.
// Under 3 seconds means a script filled it in, not a person.
$elapsed = (int) ($_POST['elapsed'] ?? 0);
if ($elapsed > 0 && $elapsed < 3) {
    respond(true, 'Thanks.');
}

// Rate limit: max 5 submissions per IP per hour.
$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile  = sys_get_temp_dir() . '/sl_rate_' . md5($ip);
$hits      = is_file($rateFile) ? (array) json_decode((string) file_get_contents($rateFile), true) : [];
$hits      = array_values(array_filter($hits, static fn($t) => $t > time() - 3600));
if (count($hits) >= 5) {
    respond(false, 'Too many enquiries from this connection. Please email us directly at ' . $config['email'] . '.', 429);
}
$hits[] = time();
@file_put_contents($rateFile, json_encode($hits));

// ------------------------------------------------------------ validate ---

/** Trim, collapse whitespace and strip header-injection characters. */
function field(string $key, int $maxLength = 300): string
{
    $value = (string) ($_POST[$key] ?? '');
    $value = str_replace(["\r", "\n", "%0a", "%0d"], ' ', $value);
    $value = trim(preg_replace('/[ \t]+/', ' ', $value) ?? '');
    return mb_substr($value, 0, $maxLength);
}

$name    = field('name', 120);
$email   = mb_strtolower(field('email', 160));
$phone   = field('phone', 40);
$message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 4000);
$eventId = field('event_id', 100);

$errors = [];
if ($name === '')                                          { $errors[] = 'name'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'email'; }
if ($phone === '' || preg_match_all('/\d/', $phone) < 6)   { $errors[] = 'phone'; }

if ($errors) {
    respond(false, 'Please check the highlighted fields and try again.', 422);
}

$lead = [
    'name'          => $name,
    'email'         => $email,
    'phone'         => $phone,
    'business_type' => field('business_type', 80),
    'business_name' => field('business_name', 120),
    'area'          => field('area', 120),
    'interest'      => field('interest', 120),
    'address'       => field('address', 200),
    'timing'        => field('timing', 120),
    'message'       => $message,
    // Screening answers from the Ecosystem ad page: which ones arrive depends on the business type.
    'gci'                => field('gci', 60),
    'agents_in_office'   => field('agents_in_office', 60),
    'properties_secured' => field('properties_secured', 60),
    'team_size'          => field('team_size', 60),
    'revenue'            => field('revenue', 60),
    // Ad tracking, so every lead can be traced back to the campaign and ad that produced it.
    'utm_source'    => field('utm_source', 120),
    'utm_medium'    => field('utm_medium', 120),
    'utm_campaign'  => field('utm_campaign', 120),
    'utm_content'   => field('utm_content', 120),
    'utm_term'      => field('utm_term', 120),
    'source'        => field('source', 80) ?: 'Website enquiry',
    'page'          => field('page', 200),
    'submitted_at'  => date('c'),
    'ip'            => $ip,
    'user_agent'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
];

$wantsRateCard = str_contains(strtolower($lead['source']), 'rate card');

// --------------------------------------------------------- notify team ---

$labels = [
    'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone',
    'business_type' => 'Business type',
    'gci' => "Last year's GCI", 'agents_in_office' => 'Agents in office', 'properties_secured' => 'Secured last year',
    'team_size' => 'Team size', 'revenue' => 'Annual revenue',
    'business_name' => 'Agency / business',
    'area' => 'Core suburb / area', 'interest' => 'Interested in',
    'address' => 'Property address', 'timing' => 'Timing', 'message' => 'Message',
    'utm_source' => 'Ad source', 'utm_medium' => 'Ad medium', 'utm_campaign' => 'Campaign',
    'utm_content' => 'Ad', 'utm_term' => 'Ad term',
];

$lines = ["New enquiry via {$lead['source']}", str_repeat('=', 46), ''];
foreach ($labels as $key => $label) {
    if (($lead[$key] ?? '') !== '') {
        $lines[] = str_pad($label . ':', 20) . $lead[$key];
    }
}
$lines[] = '';
$lines[] = str_repeat('-', 46);
$lines[] = 'Page: ' . $config['site_url'] . $lead['page'];
$lines[] = 'Time: ' . date('D j M Y, g:ia', strtotime($lead['submitted_at']));
$lines[] = 'Reply to: ' . $lead['email'];

$body    = implode("\n", $lines);
// Put the size of the business in the subject line, so the best-fit leads stand out in the inbox.
$tier    = $lead['gci'] ?: ($lead['agents_in_office'] ?: ($lead['properties_secured'] ?: ($lead['revenue'] ?: $lead['team_size'])));
$subject = sprintf(
    '[Lead] %s — %s%s',
    $lead['source'],
    $lead['name'],
    $tier !== '' ? " ({$lead['business_type']}, {$tier})" : ''
);

$sent = false;
if (!empty($config['gmail_smtp_app_password'])) {
    if (!is_dir(__DIR__ . '/../storage')) { @mkdir(__DIR__ . '/../storage', 0755, true); }
    foreach ($config['lead_recipients'] as $recipient) {
        try {
            sendViaGmailSmtp(
                $config['gmail_smtp_user'],
                $config['gmail_smtp_app_password'],
                $recipient,
                $subject,
                $body,
                $lead['name'] . ' <' . $lead['email'] . '>'
            );
            $sent = true;
        } catch (Throwable $e) {
            @file_put_contents(
                __DIR__ . '/../storage/gmail-smtp-errors.log',
                date('c') . ' ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }
} else {
    // Falls back to the server's own mail() until a Gmail app password is set —
    // see api/config.local.example.php.
    $headers = [
        'From: Social Lab Website <no-reply@sociallab.com.au>',
        'Reply-To: ' . $lead['name'] . ' <' . $lead['email'] . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: sociallab.com.au',
    ];
    foreach ($config['lead_recipients'] as $recipient) {
        $sent = mail($recipient, $subject, $body, implode("\r\n", $headers)) || $sent;
    }
}
$lead['mail_accepted'] = $sent;

// Keep a local copy of every lead as a backup, in case mail delivery fails.
$logDir = __DIR__ . '/../storage';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
@file_put_contents($logDir . '/leads.jsonl', json_encode($lead) . "\n", FILE_APPEND | LOCK_EX);

// ------------------------------------------------------- Growth Hub -----

if (!empty($config['growth_hub_url'])) {
    $payload = json_encode(['event' => 'website_lead', 'lead' => $lead]);
    $ch = curl_init($config['growth_hub_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => array_filter([
            'Content-Type: application/json',
            $config['growth_hub_secret'] ? 'Authorization: Bearer ' . $config['growth_hub_secret'] : null,
        ]),
    ]);
    $hubResponse = curl_exec($ch);
    $hubStatus   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($hubStatus < 200 || $hubStatus >= 300) {
        @file_put_contents(
            $logDir . '/growth-hub-errors.log',
            date('c') . " status={$hubStatus} " . substr((string) $hubResponse, 0, 500) . "\n",
            FILE_APPEND
        );
    }
}

// -------------------------------------------------------- meta pixel/CAPI --

/** Meta wants lowercase-trimmed values hashed with sha256, hex-encoded. */
function metaHash(string $value): string
{
    return hash('sha256', mb_strtolower(trim($value)));
}

/** Digits only, with the AU country code, per Meta's phone-matching spec. */
function metaPhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        $digits = '61' . substr($digits, 1);
    }
    return $digits;
}

if (!empty($config['meta_capi_token'])) {
    $nameParts = preg_split('/\s+/', trim($lead['name']), 2);
    $userData = array_filter([
        'em'                => [metaHash($lead['email'])],
        'ph'                => [metaHash(metaPhone($lead['phone']))],
        'fn'                => isset($nameParts[0]) && $nameParts[0] !== '' ? [metaHash($nameParts[0])] : null,
        'ln'                => isset($nameParts[1]) && $nameParts[1] !== '' ? [metaHash($nameParts[1])] : null,
        'client_ip_address' => $ip !== 'unknown' ? $ip : null,
        'client_user_agent' => $lead['user_agent'] !== '' ? $lead['user_agent'] : null,
        'fbp'               => $_COOKIE['_fbp'] ?? null,
        'fbc'               => $_COOKIE['_fbc'] ?? null,
    ]);

    $capiPayload = [
        'data' => [[
            'event_name'       => 'Lead',
            'event_time'       => strtotime($lead['submitted_at']),
            'event_id'         => $eventId !== '' ? $eventId : null,
            'action_source'    => 'website',
            'event_source_url' => $config['site_url'] . $lead['page'],
            'user_data'        => $userData,
            'custom_data'      => ['content_name' => $lead['source']],
        ]],
        'access_token' => $config['meta_capi_token'],
    ];
    if (!empty($config['meta_test_event_code'])) {
        $capiPayload['test_event_code'] = $config['meta_test_event_code'];
    }

    $ch = curl_init("https://graph.facebook.com/v21.0/{$config['meta_pixel_id']}/events");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($capiPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $capiResponse = curl_exec($ch);
    $capiStatus   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($capiStatus < 200 || $capiStatus >= 300) {
        @file_put_contents(
            $logDir . '/meta-capi-errors.log',
            date('c') . " status={$capiStatus} " . substr((string) $capiResponse, 0, 500) . "\n",
            FILE_APPEND
        );
    }
}

// --------------------------------------------- acknowledge the enquirer --

$ackSubject = $wantsRateCard
    ? 'Your Social Lab rate card'
    : 'Thanks for getting in touch with Social Lab';

$ackLines = ["Hi {$lead['name']},", ''];

if ($wantsRateCard && !empty($config['rate_card_url'])) {
    $ackLines[] = 'Thanks for requesting our property marketing rate card. Here it is:';
    $ackLines[] = '';
    $ackLines[] = $config['rate_card_url'];
    $ackLines[] = '';
    $ackLines[] = 'It covers photography, video, twilight shoots, floor plans, reels and our Social Boost add-on, with a calculator so you can build your own package.';
    $ackLines[] = '';
    $ackLines[] = 'If you would like us to put a package together for a specific listing, just reply to this email or call us.';
} elseif ($wantsRateCard) {
    // No rate card URL configured yet — promise a follow-up instead of sending a dead link.
    $ackLines[] = 'Thanks for requesting our property marketing rate card.';
    $ackLines[] = '';
    $ackLines[] = 'One of us will send it through shortly, along with anything specific to the listing you mentioned.';
    $ackLines[] = '';
    $ackLines[] = 'If anything is urgent, just reply to this email.';
} elseif (str_contains(strtolower($lead['source']), 'ecosystem ad page')) {
    $ackLines[] = 'Thanks for your interest in The Ecosystem. We have your answers.';
    $ackLines[] = '';
    $ackLines[] = 'Emily from our team will be in touch shortly for a quick 5 minute chat, to see if The Ecosystem is a fit for your business.';
    $ackLines[] = '';
    $ackLines[] = 'In the meantime, if anything is urgent, just reply to this email.';
} else {
    $ackLines[] = 'Thanks for reaching out. We have your enquiry and one of us will be in touch within one business day.';
    $ackLines[] = '';
    $ackLines[] = 'In the meantime, if anything is urgent, just reply to this email.';
}

$ackLines[] = '';
$ackLines[] = '— The Social Lab team';
$ackLines[] = $config['site_url'];

@mail($lead['email'], $ackSubject, implode("\n", $ackLines), implode("\r\n", [
    'From: Social Lab <' . $config['email'] . '>',
    'Reply-To: ' . $config['email'],
    'Content-Type: text/plain; charset=UTF-8',
]));

respond(true, 'Thanks, we have your enquiry.');
