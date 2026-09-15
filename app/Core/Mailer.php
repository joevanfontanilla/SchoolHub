<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Sends email (docs/07, docs/23). Hand-written, per docs/00: no mail library.
 *
 * Drivers, chosen by mail.driver:
 *   smtp  a hosting provider's or school's mail server: port 587 with STARTTLS (encryption "tls")
 *         or port 465 with TLS from the start (encryption "ssl"), with a username and password.
 *   log   writes each message to storage/logs/mail.log instead of sending it. For development:
 *         a link in the log works, so the preflight refuses it in production.
 *   array keeps messages in memory for the tests.
 *   none  email is off; features that need it say so.
 */
final class Mailer
{
    /** @var array<int,array{to:string,subject:string,text:string,html:string,raw:string}> */
    private static array $sent = [];

    public static function enabled(): bool
    {
        return in_array(self::driver(), ['smtp', 'log', 'array'], true);
    }

    public static function driver(): string
    {
        $driver = Config::get('mail.driver');
        if (!is_string($driver) || $driver === '') {
            return Config::get('app.env') === 'production' ? 'none' : 'log';
        }
        return strtolower($driver);
    }

    /**
     * Send one message. The HTML part is optional; the text part is what every mail program can show.
     *
     * @throws RuntimeException when the message cannot be delivered; the message never contains the password
     */
    public static function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $from = (string) Config::get('mail.from_address', '');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || self::hasLineBreak($to)) {
            throw new RuntimeException('The recipient address is not valid.');
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('mail.from_address is not set to a valid address.');
        }

        $fromName = (string) (Config::get('mail.from_name') ?? '');
        if ($fromName === '') {
            $fromName = (string) \App\Models\SystemSetting::get('school_name', (string) Config::get('app.name', 'SchoolHub'));
        }
        $raw = self::compose($from, $fromName, $to, $subject, $text, $html);

        match (self::driver()) {
            'smtp'  => self::smtp($from, $to, $raw),
            'log'   => self::log($raw),
            'array' => self::$sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => (string) $html, 'raw' => $raw],
            default => throw new RuntimeException('Email is turned off (mail.driver is "none").'),
        };
    }

    /** @return array<int,array{to:string,subject:string,text:string,html:string,raw:string}> messages kept by the array driver */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function clearSent(): void
    {
        self::$sent = [];
    }

    /** The full message: headers and a text (and HTML) body, each part base64 so no line is too long. */
    public static function compose(string $from, string $fromName, string $to, string $subject, string $text, ?string $html = null): string
    {
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::address($from, $fromName),
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];
        $textPart = chunk_split(base64_encode(self::lines($text)), 76, "\r\n");

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return implode("\r\n", $headers) . "\r\n\r\n" . $textPart;
        }

        $boundary = 'sh-' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        return implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$textPart}"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode(self::lines($html)), 76, "\r\n")
            . "--{$boundary}--\r\n";
    }

    /** A header value: plain ASCII as it is, anything else as an RFC 2047 encoded word. Line breaks are removed. */
    public static function encodeHeader(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n]+/', ' ', $value));
        return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function address(string $email, string $name): string
    {
        $name = trim((string) preg_replace('/[\r\n"]+/', ' ', $name));
        return $name === '' ? $email : self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function lines(string $body): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $body);
    }

    private static function hasLineBreak(string $value): bool
    {
        return strpbrk($value, "\r\n") !== false;
    }

    private static function log(string $raw): void
    {
        $file = STORAGE_PATH . '/logs/mail.log';
        file_put_contents($file, '==== ' . date('Y-m-d H:i:s') . " ====\r\n" . $raw . "\r\n", FILE_APPEND | LOCK_EX);
    }

    /** One SMTP conversation: greeting, EHLO, STARTTLS, AUTH, MAIL FROM, RCPT TO, DATA, QUIT. */
    private static function smtp(string $from, string $to, string $raw): void
    {
        $host = (string) Config::get('mail.host', '');
        $port = (int) Config::get('mail.port', 587);
        $encryption = strtolower((string) Config::get('mail.encryption', 'tls'));
        $timeout = (int) Config::get('mail.timeout', 15);
        $verify = (bool) Config::get('mail.verify_peer', true);
        if ($host === '') {
            throw new RuntimeException('mail.host is not set.');
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("Could not connect to the mail server {$host}:{$port} ({$error}).");
        }
        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, [220]);
            $helo = (string) (parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST) ?: 'localhost');
            $features = self::command($socket, 'EHLO ' . $helo, [250]);

            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
                    throw new RuntimeException('The mail server\'s TLS certificate could not be verified.');
                }
                $features = self::command($socket, 'EHLO ' . $helo, [250]);
            }

            $username = (string) Config::get('mail.username', '');
            if ($username !== '') {
                $password = (string) Config::get('mail.password', '');
                if (preg_match('/^250[ -]AUTH\b.*\bPLAIN\b/mi', $features)) {
                    self::command($socket, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), [235], 'AUTH PLAIN');
                } else {
                    self::command($socket, 'AUTH LOGIN', [334]);
                    self::command($socket, base64_encode($username), [334], 'the username');
                    self::command($socket, base64_encode($password), [235], 'the password');
                }
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            // A line that starts with a dot gets a second one, so it is not read as the end.
            $body = (string) preg_replace('/^\./m', '..', rtrim($raw, "\r\n"));
            self::command($socket, $body . "\r\n.", [250], 'the message');
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param array<int,int> $codes
     */
    private static function command($socket, string $line, array $codes, ?string $label = null): string
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('The connection to the mail server was lost.');
        }
        // What was sent is named, never repeated: it may be a password.
        return self::expect($socket, $codes, $label ?? strtok($line, ' :'));
    }

    /**
     * Read one reply (possibly several "250-" lines) and check its code.
     *
     * @param resource $socket
     * @param array<int,int> $codes
     */
    private static function expect($socket, array $codes, ?string $after = null): string
    {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            $meta = stream_get_meta_data($socket);
            $why = $reply === '' ? ($meta['timed_out'] ? 'no reply (timed out)' : 'no reply') : trim((string) preg_replace('/\s+/', ' ', $reply));
            throw new RuntimeException('The mail server refused ' . ($after === null ? 'the connection' : $after) . ': ' . substr($why, 0, 200));
        }
        return $reply;
    }
}
