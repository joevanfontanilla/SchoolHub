<?php
/**
 * A one-conversation SMTP server for tests/mail_test.php. It prints the port it listens on,
 * accepts one client, answers like a mail server (optionally with STARTTLS using the given
 * certificate, and AUTH PLAIN), records every line it receives to a transcript file, and exits.
 *
 *   php tests/fixtures/fake_smtp_server.php <transcript-file> [cert.pem] [reject-auth]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$transcript = (string) ($argv[1] ?? '');
$cert = (string) ($argv[2] ?? '');
$rejectAuth = ($argv[3] ?? '') === 'reject-auth';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
flush();

$client = @stream_socket_accept($server, 20);
if ($client === false) {
    exit(1);
}
stream_set_timeout($client, 20);
$log = static function (string $line) use ($transcript): void {
    file_put_contents($transcript, $line . "\n", FILE_APPEND);
};
$say = static function (string $reply) use ($client): void {
    fwrite($client, $reply . "\r\n");
};

$say('220 fake.test ESMTP ready');
$secure = false;
while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    $log(($secure ? '[tls] ' : '') . $line);
    $verb = strtoupper(strtok($line, ' :') ?: '');

    if ($verb === 'EHLO') {
        $features = ['250-fake.test'];
        if ($cert !== '' && !$secure) {
            $features[] = '250-STARTTLS';
        }
        $features[] = '250-AUTH PLAIN LOGIN';
        $features[] = '250 8BITMIME';
        $say(implode("\r\n", $features));
    } elseif ($verb === 'STARTTLS') {
        $say('220 go ahead');
        stream_context_set_option($client, 'ssl', 'local_cert', $cert);
        stream_context_set_option($client, 'ssl', 'verify_peer', false);
        if (!stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER : 0))) {
            $log('TLS FAILED');
            break;
        }
        $secure = true;
    } elseif ($verb === 'AUTH') {
        $say($rejectAuth ? '535 5.7.8 Authentication credentials invalid' : '235 2.7.0 Authentication successful');
    } elseif ($verb === 'MAIL' || $verb === 'RCPT') {
        $say('250 OK');
    } elseif ($verb === 'DATA') {
        $say('354 End data with <CR><LF>.<CR><LF>');
        while (($data = fgets($client)) !== false) {
            $data = rtrim($data, "\r\n");
            if ($data === '.') {
                break;
            }
            $log(($secure ? '[tls] ' : '') . 'DATA ' . $data);
        }
        $say('250 2.0.0 queued as FAKE123');
    } elseif ($verb === 'QUIT') {
        $say('221 bye');
        break;
    } else {
        $say('500 unknown command');
    }
}
fclose($client);
