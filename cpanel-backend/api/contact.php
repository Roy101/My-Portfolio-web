<?php
/**
 * Contact form handler — emails submissions to contact@palashroy.me.
 * Spam defenses: honeypot field, required-field + email validation,
 * length caps, header-injection stripping, and a light per-IP throttle.
 * No login/CSRF (public form); protection is honeypot + validation.
 */
declare(strict_types=1);
header('Content-Type: application/json');

function out($d): void { echo json_encode($d); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); out(['error' => 'Method not allowed']); }

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// Honeypot: real users never fill the hidden "website" field. Pretend success, drop it.
if (!empty($body['website'])) { out(['ok' => true]); }

$name    = trim((string)($body['name'] ?? ''));
$email   = trim((string)($body['email'] ?? ''));
$subject = trim((string)($body['subject'] ?? ''));
$message = trim((string)($body['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') { http_response_code(400); out(['error' => 'Please fill in your name, email and message.']); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); out(['error' => 'Please enter a valid email address.']); }
if (mb_strlen($name) > 120 || mb_strlen($subject) > 160 || mb_strlen($message) > 5000) { http_response_code(400); out(['error' => 'One of the fields is too long.']); }

// Light per-IP throttle (fail-open if the temp dir is not writable): 1 message / 20s.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$throttle = sys_get_temp_dir() . '/palashroy_contact_' . md5($ip);
$now = time();
if (is_file($throttle) && ($now - (int)@filemtime($throttle)) < 20) {
    http_response_code(429); out(['error' => 'Please wait a moment before sending another message.']);
}
@touch($throttle);

// Strip CR/LF so the values can't inject extra mail headers.
$oneLine = fn(string $s): string => str_replace(["\r", "\n"], ' ', $s);
$name = $oneLine($name);
$email = $oneLine($email);
$subject = $oneLine($subject);

$to      = 'contact@palashroy.me';
$subj    = '[palashroy.me] ' . ($subject !== '' ? $subject : ('New message from ' . $name));
$bodyTxt = "New message from your website contact form.\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . ($subject !== '' ? "Subject: {$subject}\n" : '')
    . "\nMessage:\n{$message}\n";

// From must be on your own domain for SPF/DMARC; replies go to the sender.
$headers = implode("\r\n", [
    'From: Website Contact <contact@palashroy.me>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: palashroy.me contact form',
]);

// The 5th arg sets the envelope sender (Return-Path) to your own domain so SPF/DKIM
// align with the From address — the single biggest thing that keeps this out of spam.
// (Constant value, never user input, so it's safe to pass to sendmail.)
if (!@mail($to, $subj, $bodyTxt, $headers, '-f contact@palashroy.me')) {
    http_response_code(500);
    out(['error' => 'Sorry, the message could not be sent. Please email contact@palashroy.me directly.']);
}
out(['ok' => true]);
