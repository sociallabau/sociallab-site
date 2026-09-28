<?php
/**
 * A minimal SMTP client for sending through Gmail's own servers, authenticated
 * with an app password. PHP's mail() can't do SMTP auth, and a From address of
 * @gmail.com sent any other way fails Gmail's own DMARC check at the receiving
 * end — so this is the only way to reliably send "from" sociallabau@gmail.com.
 *
 * No library: sockets + the SMTP conversation by hand, consistent with the
 * rest of this site having zero PHP dependencies.
 */

declare(strict_types=1);

/** One line of an SMTP command, reading back and checking the expected status code. */
function smtpCommand($socket, string $command, string $expectedCode): string
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed unexpectedly.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    if (!str_starts_with($response, $expectedCode)) {
        throw new RuntimeException("SMTP error, expected {$expectedCode}: " . trim($response));
    }
    return $response;
}

/**
 * Send one email through smtp.gmail.com, authenticated as $user with an app
 * password (myaccount.google.com/apppasswords — needs 2-Step Verification on).
 * Returns true on success; throws on failure so the caller can log it.
 */
function sendViaGmailSmtp(
    string $user,
    string $appPassword,
    string $to,
    string $subject,
    string $body,
    ?string $replyTo = null
): bool {
    $socket = stream_socket_client(
        'ssl://smtp.gmail.com:465',
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
    );
    if (!$socket) {
        throw new RuntimeException("Could not connect to smtp.gmail.com: {$errstr} ({$errno})");
    }

    try {
        smtpCommand($socket, '', '220');
        smtpCommand($socket, 'EHLO sociallab.com.au', '250');
        smtpCommand($socket, 'AUTH LOGIN', '334');
        smtpCommand($socket, base64_encode($user), '334');
        smtpCommand($socket, base64_encode($appPassword), '235');
        smtpCommand($socket, "MAIL FROM:<{$user}>", '250');
        smtpCommand($socket, "RCPT TO:<{$to}>", '250');
        smtpCommand($socket, 'DATA', '354');

        $headers = [
            'From: Social Lab <' . $user . '>',
            'To: ' . $to,
            'Subject: ' . $subject,
            'Content-Type: text/plain; charset=UTF-8',
            'Date: ' . date('r'),
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // Dot-stuff any line that starts with a lone '.', per RFC 5321.
        $escapedBody = preg_replace('/^\./m', '..', $body) ?? $body;
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.";
        smtpCommand($socket, $message, '250');
        smtpCommand($socket, 'QUIT', '221');
        return true;
    } finally {
        fclose($socket);
    }
}
