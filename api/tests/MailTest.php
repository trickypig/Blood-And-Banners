<?php
declare(strict_types=1);

use BloodAndBanners\Config\AppConfig;
use BloodAndBanners\Mail\LogMailer;
use BloodAndBanners\Mail\MailerFactory;
use BloodAndBanners\Mail\SmtpMailer;
use PHPMailer\PHPMailer\PHPMailer;

function mail_tests(): array
{
    $minimalEnv = "DB_DRIVER=sqlite\nDB_PATH=/tmp/x.sqlite\nAPP_SIGNING_KEY=0123456789abcdef0123456789abcdef\nCORS_ORIGIN=https://blood.trickypig.com\n";

    return [
        'log mailer appends each message to its file' => static function (): void {
            $path = tempnam(sys_get_temp_dir(), 'mail');
            try {
                $mailer = new LogMailer($path);
                $mailer->send('a@example.com', 'First', 'one', '<p>one</p>');
                $mailer->send('b@example.com', 'Second', 'two', '<p>two</p>');
                $log = (string)file_get_contents($path);
                expect_true(str_contains($log, 'To: a@example.com') && str_contains($log, 'Subject: Second'), 'both messages should be logged');
                expect_true(str_contains($log, "two"), 'text body should be logged');
            } finally {
                unlink($path);
            }
        },

        'smtp mailer configures implicit TLS on port 465' => static function (): void {
            $mailer = new SmtpMailer('smtp.ionos.com', 465, 'noreply@trickypig.com', 'secret', 'noreply@trickypig.com');
            $message = $mailer->message('player@example.com', 'Code', 'text body', '<p>html</p>');
            expect_same('smtp.ionos.com', $message->Host, 'host');
            expect_same(465, $message->Port, 'port');
            expect_same(PHPMailer::ENCRYPTION_SMTPS, $message->SMTPSecure, 'implicit TLS');
            expect_same(true, $message->SMTPAuth, 'auth');
            expect_same('noreply@trickypig.com', $message->From, 'from');
            expect_same('text body', $message->AltBody, 'plain-text alternative');
            expect_same(10, $message->Timeout, 'timeout');
        },

        'config reads mail settings with defaults' => static function () use ($minimalEnv): void {
            $path = write_test_env($minimalEnv);
            try {
                $config = AppConfig::fromFile($path);
                expect_same('', $config->smtpHost, 'no SMTP host by default');
                expect_same(465, $config->smtpPort, 'default port');
                expect_same('noreply@trickypig.com', $config->mailFrom, 'default sender');
                expect_same('https://blood.trickypig.com/play', $config->publicUrl, 'default public URL');
            } finally {
                unlink($path);
            }
            $path = write_test_env($minimalEnv . "SMTP_HOST=smtp.ionos.com\nSMTP_USER=noreply@trickypig.com\nSMTP_PASS=\"p@ss word\"\nAPP_PUBLIC_URL=http://localhost:5016\n");
            try {
                $config = AppConfig::fromFile($path);
                expect_same('smtp.ionos.com', $config->smtpHost, 'host read');
                expect_same('p@ss word', $config->smtpPassword, 'password read literally');
                expect_same('http://localhost:5016', $config->publicUrl, 'public URL read');
            } finally {
                unlink($path);
            }
        },

        'mailer factory picks log mailer without an SMTP host' => static function () use ($minimalEnv): void {
            $path = write_test_env($minimalEnv);
            try {
                expect_true(MailerFactory::fromConfig(AppConfig::fromFile($path), '/tmp/mail.log') instanceof LogMailer, 'log mailer expected');
            } finally {
                unlink($path);
            }
            $path = write_test_env($minimalEnv . "SMTP_HOST=smtp.ionos.com\nSMTP_USER=u\nSMTP_PASS=p\n");
            try {
                expect_true(MailerFactory::fromConfig(AppConfig::fromFile($path), '/tmp/mail.log') instanceof SmtpMailer, 'SMTP mailer expected');
            } finally {
                unlink($path);
            }
        },
    ];
}
