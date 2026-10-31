<?php
// ============================================================
// lib_mail.php: send one email through the vault's SMTP account (2026-10-31).
//
// Used by forgot_password.php so an account with no phone on file can still
// get its reset code (global rule: every lock has a way back in, sent
// somewhere the person actually reads). Plain sockets, STARTTLS + AUTH
// LOGIN, no library. Returns true only when the server accepted the message.
//
// pbnow_smtp_check() connects and logs in WITHOUT sending anything, so the
// credentials can be tested safely.
// ============================================================

if (!function_exists('pbnow_smtp_settings')) {
    function pbnow_smtp_settings(): array {
        global $vault_smtp_host, $vault_smtp_port, $vault_smtp_user, $vault_smtp_pass, $vault_smtp_from;
        return [
            'host' => (string)($vault_smtp_host ?? ''),
            'port' => (int)($vault_smtp_port ?? 587),
            'user' => (string)($vault_smtp_user ?? ''),
            'pass' => (string)($vault_smtp_pass ?? ''),
            'from' => (string)($vault_smtp_from ?? ''),
        ];
    }

    /** Open, greet, STARTTLS and log in. Returns the socket or throws. */
    function pbnow_smtp_open(array $c) {
        if ($c['host'] === '' || $c['user'] === '' || $c['pass'] === '') throw new RuntimeException('SMTP is not configured');
        $implicitTls = ($c['port'] === 465);
        $sock = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $errno, $errstr, 15);
        if (!$sock) throw new RuntimeException("SMTP connect failed: $errstr");
        stream_set_timeout($sock, 15);
        $read = function () use ($sock): string {
            $out = '';
            while (($line = fgets($sock, 1024)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $out;
        };
        $cmd = function (string $line, array $ok) use ($sock, $read): string {
            fwrite($sock, $line . "\r\n");
            $resp = $read();
            if (!in_array((int)substr($resp, 0, 3), $ok, true)) {
                throw new RuntimeException('SMTP said: ' . trim($resp));
            }
            return $resp;
        };
        $greet = $read();
        if ((int)substr($greet, 0, 3) !== 220) throw new RuntimeException('SMTP greeting: ' . trim($greet));
        $cmd('EHLO playpbnow.com', [250]);
        if (!$implicitTls) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
            $cmd('EHLO playpbnow.com', [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($c['user']), [334]);
        $cmd(base64_encode($c['pass']), [235]);
        return [$sock, $cmd];
    }

    /** Log in and out without sending. True when the credentials work. */
    function pbnow_smtp_check(): bool {
        try {
            [$sock, $cmd] = pbnow_smtp_open(pbnow_smtp_settings());
            $cmd('QUIT', [221]);
            fclose($sock);
            return true;
        } catch (Throwable $e) {
            error_log('pbnow_smtp_check: ' . $e->getMessage());
            return false;
        }
    }

    function pbnow_smtp_send(string $to, string $subject, string $text, string $html): bool {
        $c = pbnow_smtp_settings();
        try {
            [$sock, $cmd] = pbnow_smtp_open($c);
            $from = $c['from'] !== '' ? $c['from'] : $c['user'];
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $b = 'pbn' . bin2hex(random_bytes(8));
            $headers = [
                'From: PlayPBNow <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $subject,
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@playpbnow.com>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $b . '"',
            ];
            $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text
                  . "\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html
                  . "\r\n--$b--\r\n";
            // Dot-stuffing: a line starting with "." must be doubled.
            $data = preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body);
            $cmd($data . "\r\n.", [250]);
            $cmd('QUIT', [221]);
            fclose($sock);
            return true;
        } catch (Throwable $e) {
            error_log('pbnow_smtp_send: ' . $e->getMessage());
            return false;
        }
    }
}
