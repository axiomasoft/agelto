<?php

// Envelope-only SMTP check: never sends DATA or creates an outbound message.
declare(strict_types=1);

$host = $argv[1] ?? 'agelto-smtp';
$socket = stream_socket_client("tcp://$host:587", $errno, $error, 10);
if ($socket === false) {
    throw new RuntimeException('SMTP connection failed');
}
stream_set_timeout($socket, 10);
$read = static function () use ($socket): int {
    do {
        $line = fgets($socket);
        if ($line === false) {
            throw new RuntimeException('SMTP response missing or timed out');
        }
    } while (substr($line, 3, 1) === '-');

    return (int) substr($line, 0, 3);
};
$command = static function (string $text, int $expected) use ($socket, $read): void {
    fwrite($socket, "$text\r\n");
    $actual = $read();
    printf("%s %d\n", strtok($text, ' '), $actual);
    if ($actual !== $expected) {
        throw new RuntimeException('Unexpected SMTP status');
    }
};
if ($read() !== 220) {
    throw new RuntimeException('SMTP banner rejected');
}
$command(text: 'EHLO agelto.ru', expected: 250);
$command(text: 'MAIL FROM:<noreply@agelto.ru>', expected: 250);
$command(text: 'RCPT TO:<postmaster@agelto.ru>', expected: 250);
$command(text: 'RSET', expected: 250);
$command(text: 'MAIL FROM:<probe@example.invalid>', expected: 250);
fwrite($socket, "RCPT TO:<postmaster@agelto.ru>\r\n");
$rejected = $read();
printf("UNAUTHORIZED_SENDER_RCPT %d\n", $rejected);
if ($rejected < 500 || $rejected > 599) {
    throw new RuntimeException('Unapproved sender domain was not rejected');
}
$command(text: 'RSET', expected: 250);
$command(text: 'QUIT', expected: 221);
fclose($socket);
echo "SMTP envelope checks passed; NO DATA SENT; external delivery NOT verified\n";
