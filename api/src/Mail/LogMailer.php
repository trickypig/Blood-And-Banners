<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use RuntimeException;

/** Development mailer: appends messages to a file so sign-in codes can be read without SMTP. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $entry = sprintf("[%s] To: %s\nSubject: %s\n\n%s\n\n", gmdate('Y-m-d H:i:s'), $to, $subject, $text);
        if (file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The mail log could not be written.');
        }
    }
}