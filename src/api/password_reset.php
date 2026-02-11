<?php
session_start();

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/dbconnection.php';

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

function sendPasswordResetEmail($emailAddress, $verificationCode) {
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
        $mail->Subject = "Password Reset Code";
        $mail->Body = "Vaš kod za reset lozinke je: $verificationCode\n\nKod ističe za 10 minuta.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}

// Handle sending reset code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reset_code') {
    $professorId = null;
    $email = null;

    // Case 1: Logged in user
    if (isset($_SESSION['professor_id'])) {
        $professorId = (int) $_SESSION['professor_id'];
        try {
            $stmt = $pdo->prepare("SELECT email FROM professor WHERE id = ? AND is_active = TRUE");
            $stmt->execute([$professorId]);
            $professor = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($professor) {
                $email = $professor['email'];
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Greška baze podataka']);
            exit;
        }
    } 
    // Case 2: Email provided in request (from login page)
    elseif (isset($_POST['email'])) {
        $inputEmail = trim($_POST['email']);
        if (!filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Nevalidan format email adrese']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, email FROM professor WHERE email = ? AND is_active = TRUE");
            $stmt->execute([$inputEmail]);
            $professor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($professor) {
                $professorId = (int) $professor['id'];
                $email = $professor['email'];
            } else {
                // Security: don't reveal if email exists or not, or be helpful?
                // User asked: "checks if email exists... don't send code if email doesn't exist"
                // Returning explicit error as per instruction implies we should tell them.
                echo json_encode(['success' => false, 'message' => 'Korisnik sa tim emailom ne postoji']);
                exit;
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Greška baze podataka']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Morate unijeti email adresu']);
        exit;
    }

    if (!$professorId || !$email) {
        echo json_encode(['success' => false, 'message' => 'Profesor nije pronađen']);
        exit;
    }
    
    $verificationCode = generateVerificationCode();
    
    // Store code in session for password reset
    $_SESSION['password_reset_code'] = $verificationCode;
    $_SESSION['password_reset_email'] = $email;
    $_SESSION['password_reset_time'] = time();
    $_SESSION['password_reset_professor_id'] = $professorId;
    
    if (sendPasswordResetEmail($email, $verificationCode)) {
        // Mask email for display
        $emailParts = explode('@', $email);
        $maskedEmail = substr($emailParts[0], 0, 2) . '***@' . $emailParts[1];
        echo json_encode(['success' => true, 'message' => 'Kod je poslan na ' . $maskedEmail]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Greška pri slanju emaila']);
    }
    exit;
}

// Handle verifying reset code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_reset_code') {
    $code = trim($_POST['code'] ?? '');
    
    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Kod je obavezan']);
        exit;
    }
    
    // Check if code exists in session and matches
    if (isset($_SESSION['password_reset_code']) && 
        $_SESSION['password_reset_code'] === $code) {
        
        // Check if code is not expired (10 minutes)
        if (isset($_SESSION['password_reset_time']) && (time() - $_SESSION['password_reset_time']) < 600) {
            // Mark as verified
            $_SESSION['password_reset_verified'] = true;
            echo json_encode(['success' => true, 'message' => 'Kod je ispravan']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Kod je istekao. Zatražite novi.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Neispravan kod']);
    }
    exit;
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Check if verified
    if (!isset($_SESSION['password_reset_verified']) || $_SESSION['password_reset_verified'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Morate prvo verifikovati kod']);
        exit;
    }
    
    if (empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'Sva polja su obavezna']);
        exit;
    }
    
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Lozinke se ne poklapaju']);
        exit;
    }
    
    if (strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'message' => 'Lozinka mora imati najmanje 8 karaktera']);
        exit;
    }
    
    $professorId = $_SESSION['password_reset_professor_id'] ?? null;
    
    if (!$professorId) {
        echo json_encode(['success' => false, 'message' => 'Sesija je istekla']);
        exit;
    }
    
    try {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE user_account SET password_hash = ? WHERE professor_id = ?");
        $stmt->execute([$newHash, $professorId]);
        
        // Clear reset session data
        unset($_SESSION['password_reset_code']);
        unset($_SESSION['password_reset_email']);
        unset($_SESSION['password_reset_time']);
        unset($_SESSION['password_reset_professor_id']);
        unset($_SESSION['password_reset_verified']);
        
        echo json_encode(['success' => true, 'message' => 'Lozinka je uspješno promijenjena']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Greška pri promjeni lozinke']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nevalidni zahtjev']);
?>
