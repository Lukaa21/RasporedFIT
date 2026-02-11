<?php
session_start();
// require_once './src/includes/cache_handler.php';
// aktivirajJavaCheSaProverom(10);
$professorPanelHref = './authorization.php';
if (isset($_SESSION['user_id'])) {
    $professorPanelHref = './professor_panel.php';
}
if(isset($_SESSION['role'])){
    if($_SESSION['role']==='ADMIN'){
        $professorPanelHref = './admin_panel.php';
    }
}

?><!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FTN Sistem — Početna</title>
    <link rel="stylesheet" href="../assets/css/index.css">
</head>
<body>

<?php require __DIR__ . '/partials/header.php'; ?>

<!-- HERO SECTION -->
<section class="hero">
    <img id="hero-logo" src="/img/fit-logo.png" alt="FTN Logo">
    <h1>FIT Sistem</h1>
    <p>Moderna digitalna platforma za profesore, studente i administraciju. Jednostavan pristup rasporedima, predmetima i internim informacijama fakulteta.</p>

    <div class="hero-btns">
        <a href="<?php echo htmlspecialchars($professorPanelHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-blue">Profesor Panel</a>

    </div>
</section>

<!-- FEATURES -->
<section class="features">
    <div class="feature-card">
        <div class="feature-icon">📅</div>
        <div class="feature-title">Pametan Raspored</div>
        <div class="feature-text">Automatski organizovani pregledi predavanja, termina i učionica.</div>
    </div>

    <div class="feature-card">
        <div class="feature-icon">📚</div>
        <div class="feature-title">Upravljanje Rasporedom</div>
        <div class="feature-text">Profesori mogu jednostavno uređivati termine i učionice predavanja.</div>
    </div>

    <div class="feature-card">
        <div class="feature-icon">🔐</div>
        <div class="feature-title">Siguran Pristup</div>
        <div class="feature-text">Napredan sistem autentikacije sa ulogama za korisnike.</div>
    </div>
</section>

<footer>
    © 2026 FTN Sistem
</footer>

<script src="../assets/js/index.js"></script>
</body>
</html>