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
    'gci' => "Last year's GCI", 'properties_secured' => 'Secured last year',
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
$tier    = $lead['gci'] ?: ($lead['properties_secured'] ?: ($lead['revenue'] ?: $lead['team_size']));
$subject = sprintf(
    '[Lead] %s — %s%s',
    $lead['source'],
    $lead['name'],
    $tier !== '' ? " ({$lead['business_type']}, {$tier})" : ''
);

$headers = [
    'From: Social Lab Website <no-reply@sociallab.com.au>',
    'Reply-To: ' . $lead['name'] . ' <' . $lead['email'] . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: sociallab.com.au',
];

$sent = false;
foreach ($config['lead_recipients'] as $recipient) {
    $sent = mail($recipient, $subject, $body, implode("\r\n", $headers)) || $sent;
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
