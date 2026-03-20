<?php
header('Content-Type: application/json');

$to = 'chip@chipandkim.com';
$subject = 'PlayPBNow Mail Test';
$body = 'This is a test email from PlayPBNow.';
$headers = "From: noreply@peoplestar.com\r\nReply-To: noreply@peoplestar.com\r\nContent-Type: text/plain; charset=UTF-8";

$result = mail($to, $subject, $body, $headers);

echo json_encode([
    'mail_returned' => $result,
    'sendmail_path' => ini_get('sendmail_path'),
    'smtp' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port')
]);
