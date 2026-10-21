<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

use BloodAndBanners\Config\AppConfig;

final class MailerFactory
{
    public static function fromConfig(AppConfig $config, string $logPath): Mailer
    {
        return $config->smtpHost === ''
            ? new LogMailer($logPath)
            : new SmtpMailer($config->smtpHost, $config->smtpPort, $config->smtpUser, $config->smtpPassword, $config->mailFrom);
    }
}