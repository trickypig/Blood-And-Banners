<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $this->message($to, $subject, $text, $html)->send();
    }

    /** Builds the configured message without sending it, so settings can be tested offline. */
    public function message(string $to, string $subject, string $text, string $html): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->host;
        $mail->Port = $this->port;
        $mail->SMTPAuth = true;
        $mail->Username = $this->user;
        $mail->Password = $this->password;
        $mail->SMTPSecure = $this->port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 10;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->from, 'Blood & Banners');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
        return $mail;
    }
}