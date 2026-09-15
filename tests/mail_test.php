<?php
/**
 * Email (docs/07, docs/23): the hand-written mailer, its SMTP conversation against a real socket,
 * "Forgot password?", and the Super Admin emailing a link.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\UserAdminService;

/** Run a callback with config values changed, restoring them afterwards. */
function withConfig(array $values, callable $callback): mixed
{
    $original = [];
    foreach ($values as $key => $value) {
        $original[$key] = Config::get($key);
        Config::set($key, $value);
    }
    try {
        return $callback();
    } finally {
        foreach ($original as $key => $value) {
            Config::set($key, $value);
        }
    }
}

/**
 * Start tests/fixtures/fake_smtp_server.php, send one message to it with the given mail settings,
 * and return the lines it received plus the error the mailer threw, if any.
 *
 * @return array{lines:array<int,string>,error:?string}
 */
function smtpConversation(array $settings, ?string $cert = null, bool $rejectAuth = false, string $text = 'Hello'): array
{
    $transcript = tempnam(sys_get_temp_dir(), 'smtp');
    $command = [PHP_BINARY, __DIR__ . '/fixtures/fake_smtp_server.php', $transcript, $cert ?? '', $rejectAuth ? 'reject-auth' : ''];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    $error = null;
    try {
        withConfig($settings + ['mail.driver' => 'smtp', 'mail.host' => '127.0.0.1', 'mail.port' => $port, 'mail.from_address' => 'no-reply@school.test', 'mail.timeout' => 10], static function () use ($text): void {
            Mailer::send('student@example.com', 'Your link', $text);
        });
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $lines = file($transcript, FILE_IGNORE_NEW_LINES) ?: [];
    @unlink($transcript);
    return ['lines' => $lines, 'error' => $error];
}

/** A self-signed certificate for the fake server's STARTTLS, or null when OpenSSL cannot make one here. */
function selfSignedCertificate(): ?string
{
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    // XAMPP's PHP does not find its openssl.cnf on its own.
    foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', dirname(PHP_BINARY, 2) . '/apache/conf/openssl.cnf'] as $cnf) {
        if (getenv('OPENSSL_CONF') === false && is_file($cnf)) {
            $options['config'] = $cnf;
            break;
        }
    }
    $key = openssl_pkey_new($options);
    if ($key === false) {
        return null;
    }
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options + ['digest_alg' => 'sha256']);
    $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, $options + ['digest_alg' => 'sha256']);
    if ($cert === false) {
        return null;
    }
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem, null, $options);
    $file = tempnam(sys_get_temp_dir(), 'cert') . '.pem';
    file_put_contents($file, $certPem . $keyPem);
    return $file;
}

/** Point the request at a network address, as the web server would. */
function fromAddress(string $ip): string
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    return $ip;
}

/** A documentation-range IPv6 address unlikely to have been used within the last hour. */
function freshAddress(): string
{
    return fromAddress(sprintf('2001:db8:%x:%x::%x', random_int(1, 0xffff), random_int(1, 0xffff), random_int(1, 0xffff)));
}

return [
    'a message has clean headers: no injected lines, encoded non-ASCII subjects, and base64 parts' => function (TestRunner $t): void {
        $raw = Mailer::compose('no-reply@school.test', 'Lyceum "East" Aurora', 'ana@example.com', "Reset\r\nBcc: attacker@example.com", "Line one\n.Line starting with a dot", '<p>Hi</p>');
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $t->assertFalse((bool) preg_match('/^Bcc:/mi', $head), 'A line break in the subject cannot add a header');
        $t->assertTrue(str_contains($head, 'Subject: Reset Bcc: attacker@example.com'), 'The subject stays one line');
        $t->assertTrue(str_contains($head, 'From: Lyceum  East  Aurora <no-reply@school.test>'), 'Quotes are removed from the sender name');
        $t->assertTrue(str_contains($head, 'Content-Type: multipart/alternative'), 'Text and HTML parts');
        $t->assertEquals('=?UTF-8?B?' . base64_encode('Password · Ñiño') . '?=', Mailer::encodeHeader('Password · Ñiño'), 'Non-ASCII subjects are encoded');
        preg_match('/Content-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $body, $m);
        $t->assertEquals("Line one\r\n.Line starting with a dot", base64_decode(str_replace("\r\n", '', $m[1] ?? '')), 'The text part decodes to the text');
        foreach (explode("\r\n", $raw) as $line) {
            if (strlen($line) > 998) {
                $t->fail('A line is longer than SMTP allows');
            }
        }

        withConfig(['mail.driver' => 'array', 'mail.from_address' => 'no-reply@school.test'], static function () use ($t): void {
            foreach (["ana@example.com\r\nBcc: x@example.com", 'not-an-address'] as $bad) {
                try {
                    Mailer::send($bad, 'Hi', 'Hi');
                    $t->fail("{$bad} was accepted as a recipient");
                } catch (RuntimeException) {
                    $t->assertTrue(true);
                }
            }
        });
        withConfig(['mail.driver' => 'none'], static fn () => $t->assertFalse(Mailer::enabled(), 'Driver "none" turns email off'));
        withConfig(['mail.driver' => null, 'app.env' => 'production'], static fn () => $t->assertEquals('none', Mailer::driver(), 'Production without a driver sends nothing'));
        withConfig(['mail.driver' => null, 'app.env' => 'local'], static fn () => $t->assertEquals('log', Mailer::driver(), 'Development writes to the log'));
    },

    'SMTP: the conversation signs in, sends one message, and never repeats the password in an error' => function (TestRunner $t): void {
        $plain = smtpConversation(['mail.encryption' => 'none', 'mail.username' => 'mailer@school.test', 'mail.password' => 'S3cret-pass']);
        $t->assertEquals(null, $plain['error'], 'The message was accepted');
        $t->assertTrue(str_starts_with($plain['lines'][0] ?? '', 'EHLO '), 'Starts with EHLO');
        $t->assertTrue(in_array('AUTH PLAIN ' . base64_encode("\0mailer@school.test\0S3cret-pass"), $plain['lines'], true), 'Signs in with AUTH PLAIN when offered');
        $t->assertTrue(in_array('MAIL FROM:<no-reply@school.test>', $plain['lines'], true), 'Sender');
        $t->assertTrue(in_array('RCPT TO:<student@example.com>', $plain['lines'], true), 'Recipient');
        $t->assertTrue(in_array('DATA Subject: Your link', $plain['lines'], true), 'The message itself');
        $t->assertEquals('QUIT', end($plain['lines']), 'Ends the conversation');

        $rejected = smtpConversation(['mail.encryption' => 'none', 'mail.username' => 'mailer@school.test', 'mail.password' => 'S3cret-pass'], null, true);
        $t->assertTrue(str_contains((string) $rejected['error'], 'refused AUTH PLAIN'), 'A refused sign-in is reported: ' . $rejected['error']);
        $t->assertFalse(str_contains((string) $rejected['error'], 'S3cret-pass') || str_contains((string) $rejected['error'], base64_encode("\0mailer@school.test\0S3cret-pass")), 'The error never contains the password');

        withConfig(['mail.driver' => 'smtp', 'mail.host' => '127.0.0.1', 'mail.port' => 9, 'mail.encryption' => 'none', 'mail.from_address' => 'no-reply@school.test', 'mail.timeout' => 3], static function () use ($t): void {
            try {
                Mailer::send('student@example.com', 'Hi', 'Hi');
                $t->fail('Sending to a closed port succeeded');
            } catch (RuntimeException $e) {
                $t->assertTrue(str_contains($e->getMessage(), 'Could not connect to the mail server 127.0.0.1:9'), 'A closed port is reported');
            }
        });
    },

    'SMTP: with STARTTLS, the password and the message travel only after encryption starts' => function (TestRunner $t): void {
        $cert = selfSignedCertificate();
        if ($cert === null) {
            $t->assertTrue(true, 'OpenSSL cannot create a certificate on this machine; STARTTLS not exercised');
            return;
        }
        try {
            $tls = smtpConversation(['mail.encryption' => 'tls', 'mail.verify_peer' => false, 'mail.username' => 'mailer@school.test', 'mail.password' => 'S3cret-pass'], $cert);
            $t->assertEquals(null, $tls['error'], 'The message was accepted over TLS');
            $t->assertTrue(in_array('STARTTLS', $tls['lines'], true), 'Asks for STARTTLS');
            $plainText = array_values(array_filter($tls['lines'], static fn ($line) => !str_starts_with($line, '[tls] ')));
            $t->assertEquals(['EHLO', 'STARTTLS'], array_map(static fn ($line) => strtok($line, ' '), $plainText), 'Only EHLO and STARTTLS are sent unencrypted');
            $t->assertTrue((bool) array_filter($tls['lines'], static fn ($line) => str_starts_with($line, '[tls] AUTH PLAIN')), 'Signs in after encryption');

            $verified = smtpConversation(['mail.encryption' => 'tls', 'mail.verify_peer' => true, 'mail.username' => 'mailer@school.test', 'mail.password' => 'S3cret-pass'], $cert);
            $t->assertTrue(str_contains((string) $verified['error'], 'certificate could not be verified'), 'An unverifiable certificate is refused by default');
            $t->assertFalse((bool) array_filter($verified['lines'], static fn ($line) => str_contains($line, 'AUTH')), 'No password is sent to it');
        } finally {
            @unlink($cert);
        }
    },

    '"Forgot password?" emails a working link to the account, and the answer never reveals whether an account exists' => function (TestRunner $t): void {
        try {
            withConfig(['mail.driver' => 'array', 'mail.from_address' => 'no-reply@school.test'], static function () use ($t): void {
                Mailer::clearSent();
                $student = makeStudent();
                actingAs(null);
                $ip = freshAddress();

                $send = PasswordResetService::requestByEmail(strtoupper($student->email), $ip);
                $t->assertTrue($send instanceof Closure, 'A link is issued for the account, whatever the letter case');
                $t->assertEquals([], Mailer::sent(), 'Nothing is sent until the answer is on its way');
                $send();
                $sent = Mailer::sent();
                $t->assertEquals(1, count($sent), 'One email');
                $t->assertEquals($student->email, $sent[0]['to'], 'To the address on the account');
                preg_match('#/password/reset/([a-f0-9]{64})#', $sent[0]['text'], $m);
                $found = PasswordResetService::find($m[1] ?? '');
                $t->assertTrue($found !== null && $found['user']->id === $student->id && $found['purpose'] === PasswordResetService::RESET, 'The emailed link resets this account');
                $t->assertTrue(str_contains($sent[0]['html'], htmlspecialchars($m[1] ?? '')), 'The HTML part carries the same link');

                $audit = Database::selectOne("SELECT result, remarks, target_id FROM audit_logs WHERE action = 'password.reset_requested' AND ip_address = ? ORDER BY id DESC LIMIT 1", [$ip]);
                $t->assertEquals(['SUCCESS', $student->id], [$audit['result'], (int) $audit['target_id']], 'The request is audited');

                $unknown = PasswordResetService::requestByEmail('nobody-' . bin2hex(random_bytes(4)) . '@example.com', $ip);
                $t->assertEquals(null, $unknown, 'An unknown address sends nothing');
                $audit = Database::selectOne("SELECT result, remarks FROM audit_logs WHERE action = 'password.reset_requested' AND ip_address = ? ORDER BY id DESC LIMIT 1", [$ip]);
                $t->assertEquals('FAILURE', $audit['result'], 'It is audited as refused');
                $t->assertFalse(str_contains((string) $audit['remarks'], '@'), 'The typed address is not stored');

                foreach ([User::STATUS_DISABLED, User::STATUS_PENDING] as $status) {
                    $other = makeUser(User::ROLE_TEACHER, 'TestPass123', $status);
                    $t->assertEquals(null, PasswordResetService::requestByEmail($other->email, freshAddress()), "A {$status} account gets no link");
                }

                // Each account gets at most three links an hour.
                $count = 0;
                for ($i = 0; $i < 5; $i++) {
                    if (($step = PasswordResetService::requestByEmail($student->email, freshAddress())) !== null) {
                        $count++;
                    }
                }
                $t->assertEquals(PasswordResetService::ACCOUNT_REQUESTS_PER_HOUR - 1, $count, 'The per-account limit counts the first link too');
            });
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
            deleteTestUsers();
        }
    },

    '"Forgot password?" is limited per network address, and is off when email is off' => function (TestRunner $t): void {
        try {
            withConfig(['mail.driver' => 'array', 'mail.from_address' => 'no-reply@school.test'], static function () use ($t): void {
                $student = makeStudent();
                actingAs(null);
                $ip = freshAddress();
                withSetting('reset_requests_per_hour', '3', static function () use ($t, $ip, $student): void {
                    for ($i = 0; $i < 3; $i++) {
                        PasswordResetService::requestByEmail('nobody' . $i . '@example.com', $ip);
                    }
                    $t->assertEquals(null, PasswordResetService::requestByEmail($student->email, $ip), 'After the limit, even a real account gets nothing from that address');
                    $t->assertTrue(PasswordResetService::requestByEmail($student->email, freshAddress()) instanceof Closure, 'Another address still can');
                });
            });
            withConfig(['mail.driver' => 'none'], static function () use ($t): void {
                $student = makeStudent();
                actingAs(null);
                $t->assertEquals(null, PasswordResetService::requestByEmail($student->email, freshAddress()), 'No link when email is off');
            });
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
            deleteTestUsers();
        }
    },

    'in production, a link is emailed only when the https site address is set' => function (TestRunner $t): void {
        try {
            withConfig(['mail.driver' => 'array', 'mail.from_address' => 'no-reply@school.test', 'app.env' => 'production', 'app.base_url' => null], static function () use ($t): void {
                $student = makeStudent();
                $issued = PasswordResetService::issue($student, PasswordResetService::RESET, null);
                try {
                    PasswordResetService::emailLink($student, $issued['token'], PasswordResetService::RESET, $issued['expires_at']);
                    $t->fail('A link was built from the request host in production');
                } catch (RuntimeException $e) {
                    $t->assertTrue(str_contains($e->getMessage(), 'app.base_url'), 'Refused until the address is set');
                }
                Config::set('app.base_url', 'https://enroll.lyceum.edu.ph');
                Mailer::clearSent();
                PasswordResetService::emailLink($student, $issued['token'], PasswordResetService::RESET, $issued['expires_at']);
                $t->assertTrue(str_contains(Mailer::sent()[0]['text'] ?? '', 'https://enroll.lyceum.edu.ph/password/reset/' . $issued['token']), 'The link uses the configured address');
            });
        } finally {
            deleteTestUsers();
        }
    },

    'the Super Admin can email a link only to the account it was issued for' => function (TestRunner $t): void {
        try {
            withConfig(['mail.driver' => 'array', 'mail.from_address' => 'no-reply@school.test'], static function () use ($t): void {
                $admin = makeUser(User::ROLE_SUPER_ADMIN);
                $staff = makeUser(User::ROLE_CASHIER);
                $other = makeUser(User::ROLE_TEACHER);
                $link = UserAdminService::issueResetLink($admin, $staff->id);
                Mailer::clearSent();

                try {
                    UserAdminService::emailLink($admin, $other->id, $link['token']);
                    $t->fail('A link was emailed to a different account');
                } catch (App\Core\ValidationException) {
                    $t->assertEquals([], Mailer::sent(), 'Nothing sent to the other account');
                }
                try {
                    UserAdminService::emailLink($staff, $staff->id, $link['token']);
                    $t->fail('A non-admin emailed a link');
                } catch (Throwable) {
                    $t->assertEquals([], Mailer::sent(), 'Nothing sent by a non-admin');
                }

                UserAdminService::emailLink($admin, $staff->id, $link['token']);
                $t->assertEquals($staff->email, Mailer::sent()[0]['to'] ?? null, 'Sent to the account\'s own address');
                $t->assertTrue(str_contains(Mailer::sent()[0]['text'] ?? '', $link['token']), 'With the issued link');
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'password.link_emailed' AND target_id = ?", [$staff->id]), 'Audited');
            });
        } finally {
            deleteTestUsers();
        }
    },
];
