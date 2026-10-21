<?php
declare(strict_types=1);

namespace BloodAndBanners\Mail;

interface Mailer
{
    /** Sends one message; throws when it could not be handed to the mail server. */
    public function send(string $to, string $subject, string $text, string $html): void;
}