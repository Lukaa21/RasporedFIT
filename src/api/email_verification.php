<?php
session_start();

require __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

function generateVerificationCode($length = 6) {
    $characters = '0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

function sendVerificationEmail($emailAddress, $verificationCode) {
    $mail = new PHPMailer(true);

    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = 'smtp-relay.brevo.com'; 
        $mail->SMTPAuth = true;
        $mail->Username = 'a22987001@smtp-brevo.com';
        $mail->Password = 'G6xQXvBk3F5RcPKp'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('luka.dragicevic2004@gmail.com', 'FIT Sistem');
        $mail->addAddress($emailAddress);

        $mail->isHTML(false);
        $mail->Subject = "Email Verification";
        $mail->Body = "Your email verification code is: $verificationCode";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}

// Handle sending verification code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_code') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit;
    }
    
    $verificationCode = generateVerificationCode();
    
    // Store code and email in session
    $_SESSION['verification_code'] = $verificationCode;
    $_SESSION['verification_email'] = $email;
    $_SESSION['verification_time'] = time();
    
    if (sendVerificationEmail($email, $verificationCode)) {
        echo json_encode(['success' => true, 'message' => 'Verification code sent']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send email']);
    }
    exit;
}

// Handle verifying code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_code') {
    $code = trim($_POST['code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($code) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Code and email are required']);
        exit;
    }
    
    // Check if code exists in session and matches
    if (isset($_SESSION['verification_code']) && 
        isset($_SESSION['verification_email']) &&
        $_SESSION['verification_code'] === $code &&
        $_SESSION['verification_email'] === $email) {
        
        // Check if code is not expired (10 minutes)
        if (isset($_SESSION['verification_time']) && (time() - $_SESSION['verification_time']) < 600) {
            // Mark as verified
            $_SESSION['email_verified'] = true;
            echo json_encode(['success' => true, 'message' => 'Code verified']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Code expired']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid code']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
