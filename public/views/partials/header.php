<?php
// Header partial: shows logo and nav based on session state
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = $_SESSION['role'] ?? null;
$loggedIn = isset($_SESSION['user_id']);
$panelHref = './authorization.php';
if ($loggedIn) {
    $panelHref = './professor_panel.php';
    if ($role === 'ADMIN') {
        $panelHref = './admin_panel.php';
    }
}
?>
<header class="site-header">
    <div class="header-inner">
        <a class="logo" href="index.php">
            <img src="/img/fit-logo.png" alt="FTN Logo" id="top-logo">
            <span class="site-title">FIT Sistem</span>
        </a>
        <nav>
            <ul>
                <li><a href="index.php">Početna</a></li>
                <li><a href="<?php echo htmlspecialchars($panelHref, ENT_QUOTES, 'UTF-8'); ?>">Panel</a></li>
                <?php if ($loggedIn): ?>
                    <li><a href="logout.php">Odjavi se</a></li>
                <?php else: ?>
                    <li><a href="authorization.php">Prijava</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<style>
    /* ===== Shared Header Style (matches admin panel) ===== */

    .site-header {
        background: rgba(17, 24, 39, 0.52);
        backdrop-filter: blur(10px);
        box-shadow: var(--shadow);
        position: sticky;
        top: 0;
        z-index: 50;
    }

    /* inner wrapper */
    .header-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px 40px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* logo */
    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
        text-decoration: none;
        color: var(--text);
    }

    #top-logo {
        height: 42px;
    }

    .site-title {
        font-size: 22px;
        font-weight: 700;
        color: var(--text);
        letter-spacing: 0.3px;
    }

    /* navigation */
    .site-header nav ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        gap: 25px;
    }

    .site-header nav a {
        color: var(--muted);
        text-decoration: none;
        font-size: 16px;
        transition: color 0.2s ease;
    }

    .site-header nav a:hover {
        color: var(--accent);
    }

    /* responsive */
    @media (max-width: 768px) {
        .header-inner {
            padding: 14px 20px;
        }

        .site-title {
            display: none;
        }

        #top-logo {
            height: 36px;
        }
    }
</style>