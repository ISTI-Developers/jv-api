<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

class Mailer
{
    public static function send(
        string $to,
        string $subject,
        string $body,
        array $cc = []
    ): void {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) $_ENV['SMTP_PORT'];

        $mail->setFrom($_ENV['SMTP_FROM'], 'JV Microsite');
        $mail->addAddress($to);

        foreach ($cc as $ccEmail) {
            $mail->addCC($ccEmail);
        }

        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
    }
}
