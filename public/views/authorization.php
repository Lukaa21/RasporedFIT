<?php
session_start();
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PDO instance from config. The file now returns the PDO.
$pdo = require '../../config/dbconnection.php';

// Runtime guard to ensure $pdo exists and is PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // short English comment: ensure DB connection
    throw new RuntimeException('Missing or invalid PDO instance from config/dbconnection.php');
}

// If already logged in, redirect by role
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'ADMIN') {
        header('Location: ./admin_panel.php');
        exit;
    }
    header('Location: ./professor_panel.php');
    exit;
}

$signinError = null;
$signupError = null;
$signupSuccess = null;
$signinEmailValue = '';
$signupEmailValue = '';
$activeTab = 'signin';
$showVerification = false;

function sendVerificationEmail($emailAddress, $code) {
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = 'smtp-relay.brevo.com'; 
        $mail->SMTPAuth = true;
        // Credentials matched from src/api/email_verification.php
        $mail->Username = 'a22987001@smtp-brevo.com';
        $mail->Password = 'G6xQXvBk3F5RcPKp'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('luka.dragicevic2004@gmail.com', 'FIT Sistem');
        $mail->addAddress($emailAddress);

        $mail->isHTML(false);
        $mail->Subject = "Verifikacija naloga";
        $mail->Body = "Vas verifikacioni kod je: $code";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function buildUsernameFromFullName(string $fullName): string
{
    $fullName = strtolower(trim(preg_replace('/\s+/', ' ', $fullName)));
    if ($fullName === '') {
        return '';
    }

    $parts = explode(' ', $fullName);
    $first = $parts[0];
    $last = $parts[count($parts) - 1] ?: $first;

    return $first . '.' . $last;
}

//Signup validation
function validateSignup(PDO $pdo, string $email, string $password, string $confirm): array
{
    if ($email === '' || $password === '' || $confirm === '') {
        return ['error' => "Sva polja su obavezna."];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => "Unesite validnu email adresu."];
    }
    if (strlen($password) < 8) {
        return ['error' => "Lozinka mora imati najmanje 8 karaktera."];
    }
    if ($password !== $confirm) {
        return ['error' => "Lozinke se ne poklapaju."];
    }

    //  One query professor + existing user_account
    $stmt = $pdo->prepare(
        "SELECT p.id, p.full_name, p.is_active, ua.id AS user_id
         FROM professor p
         LEFT JOIN user_account ua ON ua.professor_id = p.id
         WHERE p.email = ?
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active']) {
        return ['error' => "Nije se moguce registrovati ovom email adresom."];
    }
    if (!empty($row['user_id'])) {
        return ['error' => "Nalog je već registrovan."];
    }

    return ['professor' => $row];
}

// Signin validation - find user by email or username and verify password
function validateSignin(PDO $pdo, string $identifier, string $password): array
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['error' => 'Unesite email i lozinku.'];
    }

    // Try find by email (typical for professors)
    $stmt = $pdo->prepare(
        "SELECT 
            ua.id AS user_id,
            ua.username,
            ua.password_hash,
            ua.role_enum,
            ua.is_active AS user_active,
            p.id AS professor_id,
            p.full_name,
            p.email,
            p.is_active AS professor_active
        FROM user_account ua
        JOIN professor p ON ua.professor_id = p.id
        WHERE p.email = ?
        LIMIT 1"
    );
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();

    //If not found, try by username(admin only)
    if (!$user) {
        $stmt = $pdo->prepare(
            "SELECT 
                ua.id AS user_id,
                ua.username,
                ua.password_hash,
                ua.role_enum,
                ua.is_active AS user_active,
                p.id AS professor_id,
                p.full_name,
                p.email,
                p.is_active AS professor_active
            FROM user_account ua
            LEFT JOIN professor p ON ua.professor_id = p.id
            WHERE ua.username = ? AND ua.role_enum = 'ADMIN'
            LIMIT 1"
        );
        $stmt->execute([$identifier]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        return ['error' => 'Pogrešan email ili lozinka.'];
    }

    //Check if account is active
    if (empty($user['user_active'])) {
        return ['error' => 'Račun nije aktivan. Kontaktirajte administratora.'];
    }

    // For non-admins require active professor
    if ($user['role_enum'] !== 'ADMIN' && !empty($user['professor_id'])) {
        if (empty($user['professor_active'])) {
            return ['error' => 'Pogrešan email ili lozinka.'];
        }
    }

    //Password check: password_verify, then md5/plain fallback
    $hash = $user['password_hash'] ?? '';
    $passwordOk = false;
    if ($hash !== '' && password_verify($password, $hash)) {
        $passwordOk = true;
    } else {
        // md5 fallback
        if ($hash !== '' && strlen($hash) === 32 && strtolower($hash) === md5($password)) {
            $passwordOk = true;
        } elseif ($hash === $password) {
            $passwordOk = true;
        }
    }

    if (!$passwordOk) {
        return ['error' => 'Pogrešan email ili lozinka.'];
    }

    return ['user' => $user];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'signin') {
        $signinEmailValue = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Use validateSignin function
        $res = validateSignin($pdo, $signinEmailValue, $password);
        if (!empty($res['error'])) {
            $signinError = $res['error'];
        } else {
            $user = $res['user'];

            // If user is ADMIN -> redirect to admin panel
            if (isset($user['role_enum']) && $user['role_enum'] === 'ADMIN') {
                // set admin session (no professor_id)
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['professor_id'] = null;
                $_SESSION['professor_email'] = $user['email'] ?? null;
                $_SESSION['professor_name'] = $user['full_name'] ?? ($user['username'] ?? 'Admin');
                $_SESSION['role'] = $user['role_enum'];

                // short English comment: redirect admin
                header("Location: ./admin_panel.php");
                exit;
            }

            //Set session for professors
            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['professor_id'] = isset($user['professor_id']) && $user['professor_id'] !== null ? (int) $user['professor_id'] : null;
            $_SESSION['professor_email'] = $user['email'] ?? null;
            $_SESSION['professor_name'] = $user['full_name'] ?? ($user['username'] ?? 'User');
            $_SESSION['role'] = $user['role_enum'] ?? null;

            header("Location: ./professor_panel.php");
            exit;
        }
    } elseif ($formType === 'signup') {
        $activeTab = 'signup';
        $signupEmailValue = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm'] ?? '';

        $validation = validateSignup($pdo, $signupEmailValue, $password, $confirmPassword);

        if (!empty($validation['error'])) {
            $signupError = $validation['error'];
        } else {
            $professor = $validation['professor'];

            $username = buildUsernameFromFullName($professor['full_name']);
            if ($username === '') {
                $signupError = "Bug ako se unesu 2 prezimena. Podaci profesora nisu validni.";
            } else {
                // Generate verification code
                $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                
                if (sendVerificationEmail($signupEmailValue, $code)) {
                     $_SESSION['signup_temp'] = [
                         'username' => $username,
                         'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                         'professor_id' => (int)$professor['id'],
                         'email' => $signupEmailValue,
                         'code' => $code,
                         'expires' => time() + 600
                     ];
                     $showVerification = true;
                } else {
                    $signupError = "Nije moguće poslati email sa verifikacionim kodom. Pokušajte ponovo.";
                }
            }
        }
    } elseif ($formType === 'verify_code') {
        $activeTab = 'signup';
        $enteredCode = trim($_POST['verification_code'] ?? '');
        $showVerification = true; // Assume stay on verify unless success/fail

        if (isset($_SESSION['signup_temp'])) {
            $data = $_SESSION['signup_temp'];
            $signupEmailValue = $data['email'];

            if (time() > $data['expires']) {
                $signupError = "Kod je istekao. Molimo registrujte se ponovo.";
                unset($_SESSION['signup_temp']);
                $showVerification = false;
            } elseif ($enteredCode === $data['code']) {
                 try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO user_account (username, password_hash, role_enum, is_active, professor_id)
                        VALUES (?, ?, 'PROFESSOR', TRUE, ?)"
                    );
                    $stmt->execute([
                        $data['username'],
                        $data['password_hash'],
                        $data['professor_id']
                    ]);

                    $signupSuccess = "Nalog je uspješno kreiran. Sada se možete prijaviti.";
                    $signupError = null;
                    $activeTab = 'signin';
                    $showVerification = false;
                    unset($_SESSION['signup_temp']);
                } catch (PDOException $e) {
                    $signupError = "Greška pri kreiranju naloga: " . $e->getMessage();
                }
            } else {
                $signupError = "Pogrešan verifikacioni kod. Pokušajte ponovo.";
            }
        } else {
            $signupError = "Sesija je istekla. Molimo registrujte se ponovo.";
            $showVerification = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sign in & Sign up</title>
    <link rel="stylesheet" href="../assets/css/base.css" />
    <link rel="stylesheet" href="../assets/css/fields.css" />
    <link rel="stylesheet" href="../assets/css/colors.css" />
    <link rel="stylesheet" href="../assets/css/stacks.css" />
    <link rel="stylesheet" href="../assets/css/tabs.css" />
</head>
<body>
<main class="container">
    <section class="card">
        <div class="header-section">
            <h1 class="title">Welcome</h1>
            <p class="subtitle">Sign in or create an account</p>
        </div>

        <!-- Tabs -->
        <div class="tabs" id="tabs" data-active="<?php echo htmlspecialchars($activeTab); ?>" aria-label="Switch forms">
            <div class="tab-indicator" aria-hidden="true"></div>
            <button type="button" id="tab-signin" class="tab-btn" aria-controls="signin" aria-selected="<?php echo $activeTab === 'signin' ? 'true' : 'false'; ?>">Sign in</button>
            <button type="button" id="tab-signup" class="tab-btn" aria-controls="signup" aria-selected="<?php echo $activeTab === 'signup' ? 'true' : 'false'; ?>">Sign up</button>
        </div>

        <!-- Stacks-->
        <div class="stacks" id="stacks" data-active="<?php echo htmlspecialchars($activeTab); ?>">
            <!-- Sign In -->
            <form id="signin" class="stack" autocomplete="on" novalidate method="post">
                <input type="hidden" name="form_type" value="signin" />
                <div class="field">
                    <label for="si-email">Email</label>
                    <input type="text" id="si-email" name="email" inputmode="email" placeholder="you@example.com" value="<?php echo htmlspecialchars($signinEmailValue); ?>" required />
                    <p class="error" data-error-for="si-email"></p>
                </div>
                <div class="field pwd-wrap">
                    <label for="si-password">Password</label>
                    <input type="password" id="si-password" name="password" placeholder="••••••••" minlength="6" required />
                    <button tabindex="-1" type="button" class="toggle-eye" data-toggle="si-password" aria-label="Show/Hide password">👁️</button>
                    <p class="error" data-error-for="si-password"></p>
                </div>
                <?php if ($signinError): ?>
                    <div class="server-error"><?php echo htmlspecialchars($signinError); ?></div>
                <?php endif; ?>
                <div class="actions">
                    <button class="btn" type="submit">Sign in</button>
                    <a href="#" id="forgotPasswordLink" onclick="handleForgotPassword(event)" style="display:block; margin-top:10px; text-align:center; font-size:0.9rem; text-decoration:none; color:#fff;">Zaboravili ste password?</a>
                    <div id="fp-error" class="server-error" style="display:none; margin-top:5px; font-size:0.8rem; text-align:center;"></div>
                </div>
            </form>

            <!-- Sign Up / Verify -->
            <?php if ($showVerification): ?>
            <form id="signup" class="stack" autocomplete="off" method="post">
                <input type="hidden" name="form_type" value="verify_code" />
                
                <div style="text-align:center; margin-bottom:20px;">
                    <h3 style="color:#2ecc71; margin-bottom:10px;">Verifikacija Email-a</h3>
                    <p style="color:#ccc; font-size:0.9rem;">
                        Poslali smo 6-cifreni kod na: <br><strong><?php echo htmlspecialchars($signupEmailValue); ?></strong>
                    </p>
                </div>

                <div class="field">
                    <label for="verification_code">Unesite kod</label>
                    <input type="text" id="verification_code" name="verification_code" placeholder="XXXXXX" required style="font-size:1.5rem; letter-spacing:5px; text-align:center; text-transform:uppercase;" />
                </div>
                
                <div class="actions">
                    <button class="btn" type="submit">Potvrdi kod</button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:block; margin-top:15px; text-align:center; color:#888; text-decoration:none; font-size:0.9rem;">Nazad (Poništi registrciju)</a>
                </div>

                <?php if ($signupError): ?>
                    <div class="server-error"><?php echo htmlspecialchars($signupError); ?></div>
                <?php endif; ?>
            </form>
            <?php else: ?>
            <form id="signup" class="stack" autocomplete="on" novalidate method="post">
                <input type="hidden" name="form_type" value="signup" />
                <div class="field">
                    <label for="su-email">Email</label>
                    <input type="email" id="su-email" name="email" inputmode="email" placeholder="you@example.com" value="<?php echo htmlspecialchars($signupEmailValue); ?>" required />
                    <p class="error" data-error-for="su-email"></p>
                </div>
                <div class="field pwd-wrap">
                    <label for="su-password">Password</label>
                    <input type="password" id="su-password" name="password" placeholder="at least 6 characters" minlength="6" required />
                    <button tabindex="-1" type="button" class="toggle-eye" data-toggle="su-password" aria-label="Show/Hide password">👁️</button>
                    <p class="help" id="pwd-hint">Use upper/lowercase letters and digits.</p>
                    <p class="error" data-error-for="su-password"></p>
                </div>
                <div class="field">
                    <label for="su-confirm">Confirm password</label>
                    <input type="password" id="su-confirm" name="confirm" placeholder="repeat your password" minlength="6" required />
                    <p class="error" data-error-for="su-confirm"></p>
                </div>
                <div class="actions">
                    <button class="btn" type="submit">Create account</button>
                </div>
                <?php if ($signupError): ?>
                    <div class="server-error"><?php echo htmlspecialchars($signupError); ?></div>
                <?php endif; ?>
                <?php if ($signupSuccess): ?>
                    <div class="success-server"><?php echo htmlspecialchars($signupSuccess); ?></div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="../assets/js/auth-form.js"></script>
<script>
function handleForgotPassword(e) {
    e.preventDefault();
    const emailInput = document.getElementById('si-email');
    const errorDiv = document.getElementById('fp-error');
    const email = emailInput.value.trim();
    
    // Clear previous errors
    errorDiv.style.display = 'none';
    errorDiv.textContent = '';
    
    if (!email) {
        errorDiv.textContent = 'Molimo unesite email adresu u polje iznad pa pokušajte ponovo.';
        errorDiv.style.display = 'block';
        emailInput.focus();
        return;
    }
    
    // Show loading state
    const link = document.getElementById('forgotPasswordLink');
    const originalText = link.textContent;
    link.textContent = 'Slanje koda...';
    link.style.pointerEvents = 'none';
    link.style.opacity = '0.7';
    
    const formData = new FormData();
    formData.append('action', 'send_reset_code');
    formData.append('email', email);
    
    fetch('../../src/api/password_reset.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'forgot_password.php';
        } else {
            errorDiv.textContent = data.message || 'Greška pri slanju koda.';
            errorDiv.style.display = 'block';
            
            // Reset link state
            link.textContent = originalText;
            link.style.pointerEvents = 'auto';
            link.style.opacity = '1';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        errorDiv.textContent = 'Došlo je do greške. Pokušajte ponovo.';
        errorDiv.style.display = 'block';
        
        // Reset link state
        link.textContent = originalText;
        link.style.pointerEvents = 'auto';
        link.style.opacity = '1';
    });
}
</script>
</body>
</html>