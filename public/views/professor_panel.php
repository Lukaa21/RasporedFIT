<?php
// start sesije
session_start();
// ucitavanje db konekcije
require_once __DIR__ . '/../../config/dbconnection.php';
// ucitavanje occupancy servisa
require_once __DIR__ . '/../../src/services/OccupancyService.php';
$occupancyService = new OccupancyService($pdo);
// logout handler
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: ./authorization.php');
    exit;
}
// provjera da li je user ulogovan
if (!isset($_SESSION['user_id'])) {
    header('Location: authorization.php');
    exit;
}
// provjera da li je profesor ulogovan
if (!isset($_SESSION['professor_id'])) {
    header('Location: authorization.php');
    exit;
}

// inicijalizacija poruka za UI
$successMessage = null;
$errorMessage = null;
// profesor id iz sesije
$professorId = (int) $_SESSION['professor_id'];
// ucitavanje podataka o profesoru
$stmt = $pdo->prepare("
    SELECT p.id, p.full_name, p.email, u.username
    FROM professor p
    LEFT JOIN user_account u ON u.professor_id = p.id
    WHERE p.id = ? AND p.is_active = TRUE
");
$stmt->execute([$professorId]);
$currentProfessor = $stmt->fetch(PDO::FETCH_ASSOC);
// fallback ako profesor nije aktivan / ne postoji
if (!$currentProfessor) {
    session_unset();
    session_destroy();
    header('Location: ./authorization.php');
    exit;
}
// helper funkcija za generisanje username-a iz imena
function buildUsernameFromFullName(string $fullName): string
{
    $fullName = strtolower(trim(preg_replace('/\s+/', ' ', $fullName)));
    if ($fullName === '') return '';

    $parts = explode(' ', $fullName);
    $first = $parts[0];
    $last = $parts[count($parts) - 1];
    if ($last === '') $last = $first;

    return $first . '.' . $last;
}
// ucitavanje postojece raspolozivosti profesora
$stmt = $pdo->prepare("
    SELECT weekday, start_time, end_time
    FROM professor_availability
    WHERE professor_id = ?
    ORDER BY weekday, start_time
");
$stmt->execute([$professorId]);
$existingAvailability = $stmt->fetchAll(PDO::FETCH_ASSOC);
// POST router za profile/password update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
// akcija: update profila
    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        if ($fullName === '') {
            $errorMessage = "Ime i prezime je obavezno.";
        } else {
            $username = buildUsernameFromFullName($fullName);
            if ($username === '') {
                $errorMessage = "Ime i prezime nisu validni.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE professor SET full_name = ? WHERE id = ?");
                    $stmt->execute([$fullName, $professorId]);

                    $stmt = $pdo->prepare("UPDATE user_account SET username = ? WHERE professor_id = ?");
                    $stmt->execute([$username, $professorId]);

                    $pdo->commit();

                    $currentProfessor['full_name'] = $fullName;
                    $currentProfessor['username'] = $username;
                    $_SESSION['professor_name'] = $fullName;
                    $successMessage = "Podaci su uspješno ažurirani.";
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errorMessage = "Greška pri čuvanju podataka: " . $e->getMessage();
                }
            }
        }
    }
    // akcija: update passworda
    elseif ($action === 'update_password') {
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errorMessage = "Sva polja su obavezna.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "Novi password i potvrda se ne poklapaju.";
        } elseif (strlen($newPassword) < 8) {
            $errorMessage = "Novi password mora imati najmanje 8 karaktera.";
        } else {
            $stmt = $pdo->prepare("SELECT password_hash FROM user_account WHERE professor_id = ?");
            $stmt->execute([$professorId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($oldPassword, $account['password_hash'])) {
                $errorMessage = "Stari password nije tačan.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("UPDATE user_account SET password_hash = ? WHERE professor_id = ?");
                    $stmt->execute([$newHash, $professorId]);
                    $successMessage = "Password je uspješno promijenjen.";
                } catch (PDOException $e) {
                    $errorMessage = "Greška pri promjeni passworda: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Profesora</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

    <link rel="stylesheet" href="../assets/css/base.css" />
    <link rel="stylesheet" href="../assets/css/fields.css" />
    <link rel="stylesheet" href="../assets/css/colors.css" />
    <link rel="stylesheet" href="../assets/css/stacks.css" />
    <link rel="stylesheet" href="../assets/css/tabs.css" />
    <link rel="stylesheet" href="../assets/css/table.css" />
    <link rel="stylesheet" href="../assets/css/occupancy.css">
    <link rel="stylesheet" href="../assets/css/profesor_profile.css">
    <link rel="stylesheet" href="../assets/css/admin.css" />
    <link rel="stylesheet" href="../assets/css/professor_notes_modal.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>



</head>
<body>
<!-- header partial include -->
<?php require __DIR__ . '/partials/header.php'; ?>
<!-- glavni container -->
<div class="container mt-4">
    <h2>Profil Profesora</h2>
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <!-- navigacija tabova -->
    <ul class="nav nav-tabs" id="profTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-profile" data-bs-toggle="tab" data-bs-target="#profileTab" type="button">Profil</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-events" data-bs-toggle="tab" data-bs-target="#eventsTab" type="button">Događaji</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-courses" data-bs-toggle="tab" data-bs-target="#coursesTab" type="button">Predmeti & Kolokvijumi</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-avail" data-bs-toggle="tab" data-bs-target="#availabilityTab" type="button">Raspoloživost</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-occupancy" data-bs-toggle="tab" data-bs-target="#occupancyTab" type="button">Zauzetost sala</button>
        </li>
        <li class="nav-item">
            <button class="nav-link"
                    id="schedule-tab-btn"
                    data-bs-toggle="tab"
                    data-bs-target="#scheduleTab">
                Moj raspored
            </button>
        </li>

    </ul>

    <div class="tab-content mt-3">
        <!-- PROFILE TAB -->
        <div class="tab-pane fade show active" id="profileTab">
            <div class="edit-controls mb-3">
                <button id="toggleEditBtn" class="btn btn-secondary">Edit profil</button>
            </div>

            <div id="profileView">
                <p><strong>Ime i prezime:</strong> <?php echo htmlspecialchars($currentProfessor['full_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($currentProfessor['email']); ?></p>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($currentProfessor['username'] ?? ''); ?></p>
            </div>

            <div id="profileEdit" class="d-none">
                <form method="post">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Ime i prezime:</label>
                        <input type="text" id="full_name" name="full_name" class="form-control" value="<?php echo htmlspecialchars($currentProfessor['full_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email:</label>
                        <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($currentProfessor['email']); ?>" readonly>
                    </div>
                    <button type="submit" class="btn btn-primary">Sačuvaj izmjene</button>
                </form>
                <button id="openModalBtn" class="btn btn-warning mt-2">Promijeni password</button>
                <a href="forgot_password.php" class="btn btn-link mt-2">Zaboravili ste password?</a>
            </div>
        </div>

        <!-- EVENTS / NOTES TAB -->
        <div class="tab-pane fade" id="eventsTab">
            <h3>Kalendar događaja</h3>

            <div class="alert alert-info">
                Kliknite na dan da dodate napomenu.
            </div>

            <div id="events-calendar"></div>
        </div>


        <!-- COURSES TAB -->
        <div class="tab-pane fade" id="coursesTab">
            <h3>Moji predmeti i izbor nedjelje kolokvijuma</h3>
            <div id="colloquiumMessage" class="alert d-none mb-3"></div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                    <tr>
                        <th>Predmet</th>
                        <th>Semestar</th>
                        <th>Uloga</th>
                        <th>Kolokvijum 1 (sedmica)</th>
                        <th>Kolokvijum 2 (sedmica)</th>
                    </tr>
                    </thead>
                    <tbody id="coursesTableBody">
                    <?php
                    try {

                        $stmtYear = $pdo->query("SELECT * FROM academic_year WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
                        $academicYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

                        $winterStart = $academicYear ? strtotime($academicYear['winter_semester_start']) : null;
                        $summerStart = $academicYear ? strtotime($academicYear['summer_semester_start']) : null;

                        $stmt = $pdo->prepare("
                            SELECT c.id, c.name, c.semester, cp.is_assistant, 
                                   c.colloquium_1_week, c.colloquium_2_week
                            FROM course_professor cp
                            JOIN course c ON c.id = cp.course_id
                            WHERE cp.professor_id = ?
                            ORDER BY c.name
                        ");
                        $stmt->execute([$professorId]);
                        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (!$courses) {
                            echo "<tr><td colspan='5'>Nema pridruženih predmeta.</td></tr>";
                        } else {
                            foreach($courses as $c) {
                                $role = $c['is_assistant'] ? 'Asistent' : 'Profesor';
                                $col1Val = $c['colloquium_1_week'];
                                $col2Val = $c['colloquium_2_week'];

                                $isWinter = ($c['semester'] % 2 != 0);
                                $semStart = $isWinter ? $winterStart : $summerStart;
                                ?>
                                <tr data-course-id="<?php echo (int)$c['id']; ?>">
                                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['semester']); ?></td>
                                    <td><?php echo $role; ?></td>
                                    <td>
                                        <select class="form-select form-select-sm colloquium-1-select" data-course-id="<?php echo (int)$c['id']; ?>">
                                            <option value="">-- Izaberi --</option>
                                            <option value="1" <?php echo ($col1Val == 1) ? 'selected' : ''; ?>>Ne održava se</option>
                                            <?php for($w=5; $w<=13; $w++):
                                                $dateLabel = "";
                                                if ($semStart) {
                                                    $wStart = strtotime("+" . ($w - 1) . " weeks", $semStart);
                                                    $wEnd = strtotime("+6 days", $wStart);
                                                    $dateLabel = " (" . date('d.m.Y', $wStart) . "-" . date('d.m.Y', $wEnd) . ")";
                                                }
                                                ?>
                                                <option value="<?php echo $w; ?>" <?php echo ($col1Val == $w) ? 'selected' : ''; ?>>
                                                    <?php echo $w; ?>. sedmica<?php echo $dateLabel; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select form-select-sm colloquium-2-select" data-course-id="<?php echo (int)$c['id']; ?>">
                                            <option value="">-- Izaberi --</option>
                                            <option value="1" <?php echo ($col2Val == 1) ? 'selected' : ''; ?>>Ne održava se</option>
                                            <?php for($w=5; $w<=13; $w++):
                                                $dateLabel = "";
                                                if ($semStart) {
                                                    $wStart = strtotime("+" . ($w - 1) . " weeks", $semStart);
                                                    $wEnd = strtotime("+6 days", $wStart);
                                                    $dateLabel = " (" . date('d.m.Y', $wStart) . "-" . date('d.m.Y', $wEnd) . ")";
                                                }
                                                ?>
                                                <option value="<?php echo $w; ?>" <?php echo ($col2Val == $w) ? 'selected' : ''; ?>>
                                                    <?php echo $w; ?>. sedmica<?php echo $dateLabel; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                    } catch(PDOException $e) {
                        echo "<tr><td colspan='5'>Greška: ".htmlspecialchars($e->getMessage())."</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($courses)): ?>
                <button type="button" id="saveColloquiumWeeksBtn" class="btn btn-primary">
                    <span class="btn-text">Sačuvaj sedmice kolokvijuma</span>
                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                </button>
            <?php endif; ?>
        </div>

        <!-- AVAILABILITY TAB -->
        <div class="tab-pane fade" id="availabilityTab">
            <h3>Raspoloživost</h3>

            <div id="availability-view-mode">
                <?php if (count($existingAvailability) > 0): ?>
                    <h5 class="mt-3">Vaši trenutni termini:</h5>
                    <div class="table-responsive mt-2">
                        <table class="table table-bordered table-hover" style="background-color: white;">
                            <thead class="table-light">
                            <tr>
                                <th style="width: 50%;">Dan</th>
                                <th style="width: 50%;">Vrijeme</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            $daysMap = [1=>'Ponedeljak', 2=>'Utorak', 3=>'Srijeda', 4=>'Četvrtak', 5=>'Petak'];
                            foreach ($existingAvailability as $slot):
                                $dName = $daysMap[$slot['weekday']] ?? 'Nepoznato';
                                $tFrom = date('H:i', strtotime($slot['start_time']));
                                $tTo   = date('H:i', strtotime($slot['end_time']));
                                ?>
                                <tr>
                                    <td class="fw-bold text-secondary"><?php echo $dName; ?></td>
                                    <td><?php echo $tFrom . ' – ' . $tTo; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mt-3">Niste još definisali termine raspoloživosti.</div>
                <?php endif; ?>

                <button id="btn-enable-edit" class="btn btn-primary mt-3">
                    <?php echo (count($existingAvailability) > 0) ? 'Ažuriraj raspoloživost' : 'Dodaj raspoloživost'; ?>
                </button>
            </div>
            <div id="availability-edit-mode" class="d-none">
                <div class="alert alert-warning mt-2">
                    <small>Kliknite i prevucite mišem preko kalendara da označite termine. Kliknite na termin da ga obrišete.</small>
                </div>

                <div id="availability-calendar" style="margin-top:10px;"></div>

                <h5 class="mt-4">Novi termini za čuvanje:</h5>
                <ul id="availability-output"></ul>

                <div class="mt-3">
                    <button id="save-availability" class="btn btn-success me-2">Sačuvaj raspoloživost</button>
                    <button id="cancel-edit" class="btn btn-secondary">Odustani</button>
                </div>
            </div>
        </div>

        <!-- OCCUPANCY TAB -->
        <div class="tab-pane fade" id="occupancyTab">
            <?php
            $y = $occupancyService->getActiveYear();
            $year_label = $y ? $y['year_label'] : "Nije definisana";
            $year_id = $y ? $y['id'] : 0;

            $slots = [
                ['08:15', '09:00'], ['09:15', '10:00'], ['10:15', '11:00'],
                ['11:15', '12:00'], ['12:15', '13:00'], ['13:15', '14:00'],
                ['14:15', '15:00'], ['15:15', '16:00'], ['16:15', '17:00'],
                ['17:15', '18:00'], ['18:15', '19:00'], ['19:15', '20:00'],
                ['20:15', '21:00']
            ];

            $rooms = $occupancyService->getRooms();
            $occupancy = ($year_id > 0) ? $occupancyService->getOccupancy($year_id) : [];

            $days = [1 => 'PONEDJELJAK', 2 => 'UTORAK', 3 => 'SRIJEDA', 4 => 'ČETVRTAK', 5 => 'PETAK'];
            ?>

            <div class="occupancy-header mb-3">
                <h4>Zauzetost sala - Akademska godina: <?= htmlspecialchars($year_label) ?></h4>
            </div>

            <div class="legend-container mb-3">
                <div class="legend-item"><div class="legend-color faculty-fit"></div> <span>FIT</span></div>
                <div class="legend-item"><div class="legend-color faculty-feb"></div> <span>FEB</span></div>
                <div class="legend-item"><div class="legend-color faculty-mts"></div> <span>MTS</span></div>
                <div class="legend-item"><div class="legend-color faculty-pf"></div> <span>PF</span></div>
                <div class="legend-item"><div class="legend-color faculty-fsj"></div> <span>FSJ</span></div>
                <div class="legend-item"><div class="legend-color faculty-fvu"></div> <span>FVU</span></div>
            </div>

            <div class="occupancy-container" style="max-height: 800px; overflow-y: auto;">
                <table class="occupancy-table">
                    <thead>
                    <tr>
                        <th rowspan="2" class="time-col">Vrijeme</th>
                        <?php foreach ($days as $dayNum => $dayName): ?>
                            <th colspan="<?= count($rooms) ?>"><?= $dayName ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($days as $dayNum => $dayName): ?>
                            <?php foreach ($rooms as $room): ?>
                                <th><?= htmlspecialchars($room['code']) ?></th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($slots as $slot): ?>
                        <tr>
                            <td class="time-col"><?= $slot[0] ?> - <?= $slot[1] ?></td>
                            <?php foreach ($days as $dayNum => $dayName): ?>
                                <?php foreach ($rooms as $room): ?>
                                    <?php
                                    $key = $room['id'] . '-' . $dayNum . '-' . $slot[0];
                                    $occ = $occupancy[$key] ?? null;
                                    $class = "";
                                    if ($occ) {
                                        $class = "faculty-" . strtolower($occ['faculty_code']);
                                    }
                                    ?>
                                    <td class="occupancy-cell <?= $class ?>"
                                        title="<?= $occ ? "Zauzeto: {$occ['faculty_code']}\nTip: {$occ['source_type']}" : "Slobodno" ?>">
                                        <?php if ($occ): ?>
                                            <div class="cell-info"><?= htmlspecialchars($occ['faculty_code']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
        <!-- SCHEDULE TAB -->
        <div class="tab-pane fade" id="scheduleTab">
            <h3>Moj raspored časova</h3>
            <div id="professor-schedule-container"></div>
        </div>
    </div>

    <!-- password modal -->
    <div class="modal fade" id="passwordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <input type="hidden" name="action" value="update_password">
                    <div class="modal-header"><h5 class="modal-title">Promjena passworda</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3"><label for="oldPassword" class="form-label">Stari password</label>
                            <input type="password" class="form-control" id="oldPassword" name="old_password" required></div>

                        <div class="mb-3"><label for="newPassword" class="form-label">Novi password</label>
                            <input type="password" class="form-control" id="newPassword" name="new_password" required></div>

                        <div class="mb-3"><label for="confirmPassword" class="form-label">Potvrdi novi password</label>
                            <input type="password" class="form-control" id="confirmPassword" name="confirm_password" required></div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Sačuvaj</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- note modal -->
    <div id="noteModal" class="note-modal hidden">
        <div class="note-modal-content">
            <button class="note-modal-close" id="noteModalClose">✖</button>

            <h3 id="noteModalTitle">Napomena</h3>

            <textarea id="noteTextarea" placeholder="Unesi napomenu..."></textarea>

            <div class="note-modal-actions">
                <button id="noteSaveBtn">✔</button>
                <button id="noteDeleteBtn" class="danger">🗑</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.SERVER_EXISTING_AVAILABILITY = <?php echo json_encode($existingAvailability); ?>;
    </script>
    <script>
        // kolokvijum weeks save script
        document.addEventListener('DOMContentLoaded', function() {
            const saveBtn = document.getElementById('saveColloquiumWeeksBtn');
            if (!saveBtn) return;

            const messageDiv = document.getElementById('colloquiumMessage');

            function showMessage(type, text) {
                messageDiv.className = 'alert alert-' + type + ' mb-3';
                messageDiv.textContent = text;
                messageDiv.classList.remove('d-none');
                setTimeout(() => messageDiv.classList.add('d-none'), 5000);
            }

            function showLoading(show) {
                const textSpan = saveBtn.querySelector('.btn-text');
                const spinner = saveBtn.querySelector('.spinner-border');
                if (show) {
                    textSpan.classList.add('d-none');
                    spinner.classList.remove('d-none');
                    saveBtn.disabled = true;
                } else {
                    textSpan.classList.remove('d-none');
                    spinner.classList.add('d-none');
                    saveBtn.disabled = false;
                }
            }


            saveBtn.addEventListener('click', function() {
                const rows = document.querySelectorAll('#coursesTableBody tr[data-course-id]');
                const data = [];
                let hasValidationError = false;

                rows.forEach(row => {
                    const courseId = row.getAttribute('data-course-id');
                    const col1Select = row.querySelector('.colloquium-1-select');
                    const col2Select = row.querySelector('.colloquium-2-select');

                    const col1Value = col1Select.value;
                    const col2Value = col2Select.value;

                    // Validacija: ako su oba odabrana i nisu "ne odrzava se", col2 > col1
                    if (col1Value && col2Value && col1Value !== '1' && col2Value !== '1') {
                        if (parseInt(col2Value) <= parseInt(col1Value)) {
                            hasValidationError = true;
                            row.classList.add('table-danger');
                            showMessage('danger', 'Greška: Kolokvijum 2 mora biti nakon Kolokvijuma 1');
                        } else {
                            row.classList.remove('table-danger');
                        }
                    } else {
                        row.classList.remove('table-danger');
                    }

                    data.push({
                        course_id: courseId,
                        colloquium_1_week: col1Value || null,
                        colloquium_2_week: col2Value || null
                    });
                });

                if (hasValidationError) return;

                showLoading(true);

                fetch('api/professor_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'save_colloquium_weeks',
                        data: data
                    })
                })
                    .then(response => response.json())
                    .then(result => {
                        showLoading(false);
                        if (result.success) {
                            showMessage('success', 'Sedmice kolokvijuma su uspješno sačuvane!');
                        } else {
                            showMessage('danger', 'Greška: ' + (result.error || 'Nepoznata greška'));
                        }
                    })
                    .catch(error => {
                        showLoading(false);
                        showMessage('danger', 'Greška pri slanju zahtjeva');
                        console.error(error);
                    });
            });
        });
    </script>
    <script src="../assets/js/professor_tabs.js"></script>



    <script>
        // events calendar state varijable
        let eventsCalendar = null;
        let eventsCalendarInitialized = false;
        let holidayDates = new Set();

        const eventsTabBtn = document.getElementById('tab-events');

        eventsTabBtn.addEventListener('shown.bs.tab', function () {
            setTimeout(initEventsCalendar, 50);
        });
        // init events calendar kada se otvori tab
        function initEventsCalendar() {

            if (eventsCalendarInitialized) {
                eventsCalendar.updateSize();
                return;
            }

            const calendarEl = document.getElementById('events-calendar');
            if (!calendarEl) return;

// fetch holidays iz professor_api
            fetch('api/professor_api.php?action=get_holidays')
                .then(r => r.json())
                .then(data => {

                    data.forEach(h => holidayDates.add(h.date));

                    let notesByDate = {};

                    fetch('api/professor_api.php?action=get_notes')
                        .then(r => r.json())
                        .then(notes => {
                            notes.forEach(n => {
                                notesByDate[n.note_date] = n.content;
                            });
                        });


                    // fullcalendar instanca za notes
                    eventsCalendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        locale: 'sr',
                        firstDay: 1,
                        height: 'auto',

                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: ''
                        },
                        // render pin ikone za note event
                        eventContent: function(arg) {
                            const note = arg.event.extendedProps.note;

                            if (!note || note.trim() === '') {
                                return { domNodes: [] };
                            }

                            const pin = document.createElement('span');
                            pin.textContent = '📌';
                            pin.style.cursor = 'pointer';
                            pin.style.fontSize = '16px';

                            return { domNodes: [pin] };
                        },
                        // klik na dan — pravljenje privremenog note eventa
                        dateClick(info) {
                            const event = eventsCalendar.addEvent({
                                title: '',
                                start: info.dateStr,
                                allDay: true,
                                extendedProps: {
                                    note: '',
                                    noteId: null
                                }
                            });

                            window.currentEvent = event;

                            const modal = document.getElementById('noteModal');
                            const textarea = document.getElementById('noteTextarea');
                            const deleteBtn = document.getElementById('noteDeleteBtn');

                            textarea.value = '';
                            deleteBtn.style.display = 'none';

                            modal.classList.remove('hidden');
                        },
                        // loader events (notes) iz baze
                        events: function(fetchInfo, successCallback) {
                            fetch('api/professor_api.php?action=get_notes')
                                .then(r => r.json())
                                .then(data => {
                                    const events = data.map(n => ({
                                        title: '',
                                        start: n.note_date,
                                        allDay: true,
                                        backgroundColor: '#3498db',
                                        extendedProps: {
                                            note: n.content,
                                            noteId: n.id
                                        }
                                    }));

                                    successCallback(events);
                                });
                        },
                        // highlight praznika i dana sa notes
                        dayCellDidMount(info) {
                            const y = info.date.getFullYear();
                            const m = String(info.date.getMonth() + 1).padStart(2, '0');
                            const d = String(info.date.getDate()).padStart(2, '0');
                            const dateStr = `${y}-${m}-${d}`;

                            if (holidayDates.has(dateStr)) {
                                const frame = info.el.querySelector('.fc-daygrid-day-frame');
                                if (frame) {
                                    frame.style.backgroundColor = 'rgba(148,250,192,0.6)';
                                    frame.style.borderRadius = '6px';
                                }
                            }

                            if (notesByDate[dateStr]) {
                                const frame = info.el.querySelector('.fc-daygrid-day-frame');
                                if (frame) {
                                    frame.style.border = '2px solid #f1c40f';
                                }
                            }
                        },
                        // klik na postojeci note event
                        eventClick: function(info) {
                            const modal = document.getElementById('noteModal');
                            const textarea = document.getElementById('noteTextarea');
                            const deleteBtn = document.getElementById('noteDeleteBtn');

                            const note = info.event.extendedProps.note || '';

                            textarea.value = note;

                            deleteBtn.style.display = note.trim() ? 'inline-block' : 'none';

                            modal.classList.remove('hidden');

                            window.currentEvent = info.event;
                        },


                    });
                    const modal = document.getElementById('noteModal');
                    const closeBtn = document.getElementById('noteModalClose');
// note modal close dugme
                    closeBtn.addEventListener('click', () => {
                        modal.classList.add('hidden');
                    });

                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) {
                            modal.classList.add('hidden');
                        }
                    });
                    // save note handler
                    document.getElementById('noteSaveBtn').addEventListener('click', () => {
                        const textarea = document.getElementById('noteTextarea');
                        const content = textarea.value.trim();

                        if (!content) return;

                        const event = window.currentEvent;
                        const noteId = event.extendedProps.noteId;

                        if (noteId) {
                            fetch('api/professor_api.php?action=update_note', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    id: noteId,
                                    content: content
                                })
                            })
                                .then(r => r.json())
                                .then(res => {
                                    if (!res.success) return;

                                    event.setExtendedProp('note', content);
                                    modal.classList.add('hidden');
                                });

                            return;
                        }


                        fetch('api/professor_api.php?action=save_note', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                date: event.startStr,
                                content: content
                            })
                        })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) return;

                                event.setExtendedProp('note', content);
                                event.setExtendedProp('noteId', res.id);
                                modal.classList.add('hidden');
                            });
                    });
                    // delete note handler
                    document.getElementById('noteDeleteBtn').addEventListener('click', () => {
                        const event = window.currentEvent;
                        const noteId = event.extendedProps.noteId;

                        if (!noteId) return;

                        fetch('api/professor_api.php?action=delete_note', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: noteId })
                        })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) return;


                                event.remove();

                                document.getElementById('noteModal').classList.add('hidden');
                            });
                    });
                    document.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') {
                            modal.classList.add('hidden');
                        }
                    });

                    eventsCalendar.render();
                    eventsCalendarInitialized = true;
                });
        }
    </script>



    <script>
        // professor schedule calendar script
        document.addEventListener('DOMContentLoaded', function () {

            let scheduleCalendar = null;
            let initialized = false;

            const tabBtn = document.getElementById('schedule-tab-btn');
// init schedule calendar na tab open
            tabBtn.addEventListener('shown.bs.tab', function () {

                if (initialized) {
                    scheduleCalendar.updateSize();
                    return;
                }

                initialized = true;

                const container = document.getElementById('professor-schedule-container');
                container.innerHTML = '';
                container.style.minHeight = '600px';

                scheduleCalendar = new FullCalendar.Calendar(container, {
                    initialView: 'timeGridWeek',
                    locale: 'sr',
                    firstDay: 1,
                    hiddenDays: [0, 6],
                    allDaySlot: false,
                    headerToolbar: false,
                    height: 'auto',

                    slotMinTime: '08:00:00',
                    slotMaxTime: '21:00:00',

                    dayHeaderContent: function(arg) {
                        const days = ['PON', 'UTO', 'SRE', 'ČET', 'PET'];
                        return days[arg.date.getDay() - 1] || '';
                    },

                    events: []
                });

                scheduleCalendar.render();
// fetch schedule iz professor_api
                fetch(window.location.pathname + '?action=get_professor_schedule')
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.schedules) return;

                        const firstId = data.schedule_ids[0];
                        const semesters = data.schedules[firstId] || {};

                        const events = [];

                        Object.values(semesters).forEach(list => {
                            list.forEach(e => {
                                events.push({
                                    title: e.course + (e.room ? ` (${e.room})` : ''),
                                    daysOfWeek: [e.day - 1],
                                    startTime: e.start,
                                    endTime: e.end
                                });
                            });
                        });

                        scheduleCalendar.addEventSource(events);
                    });
            });

        });
    </script>




</body>
</html>
