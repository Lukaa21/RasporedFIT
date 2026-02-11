<?php
session_start();
require_once __DIR__ . '/../../config/dbconnection.php';
require_once __DIR__ . '/../../src/services/OccupancyService.php';
$occupancyService = new OccupancyService($pdo);
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS academic_year (
            id bigserial PRIMARY KEY,
            year_label varchar(9) NOT NULL,
            winter_semester_start date NOT NULL,
            summer_semester_start date NOT NULL,
            is_active boolean DEFAULT true
        )
    ");
// Ensure room_occupancy table exists
    try {
        // Moved to OccupancyService logic
    } catch (PDOException $e) {
        // Ignore if exists
    }
} catch (PDOException $e) {
    // Ignore error if tables already exist or handle appropriately
}
//DEADLINE
$current_deadline = $pdo->query("SELECT value FROM config WHERE \"key\" = 'schedule_deadline'")->fetchColumn() ?: '';

if (isset($_GET['action']) && $_GET['action'] === 'getschedule') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // Get all distinct schedule_ids (latest 6)
        $scheduleStmt = $pdo->prepare("
            SELECT DISTINCT schedule_id 
            FROM academic_event 
            WHERE schedule_id IS NOT NULL 
            ORDER BY schedule_id DESC 
            LIMIT 6
        ");
        $scheduleStmt->execute();
        $scheduleIds = $scheduleStmt->fetchAll(PDO::FETCH_COLUMN);

        // Reverse to show oldest first (1, 2, 3, 4, 5, 6)
        $scheduleIds = array_reverse($scheduleIds);

        $stmt = $pdo->prepare("
            SELECT 
                ae.schedule_id,
                ae.day,
                ae.starts_at,
                ae.ends_at,
                c.name AS coursename,
                r.code AS roomcode,
                c.semester,
                ae.type_enum,
                c.is_online,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = FALSE), '') AS professors,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = TRUE), '') AS assistants
            FROM academic_event ae
            JOIN course c ON ae.course_id = c.id
            LEFT JOIN room r ON ae.room_id = r.id
            LEFT JOIN course_professor cp ON cp.course_id = c.id
            LEFT JOIN professor p ON p.id = cp.professor_id
            WHERE ae.type_enum IN ('LECTURE', 'EXERCISE', 'LAB')
              AND ae.schedule_id IN (" . implode(',', array_fill(0, count($scheduleIds), '?')) . ")
            GROUP BY ae.schedule_id, ae.day, ae.starts_at, ae.ends_at, c.name, r.code, c.semester, ae.type_enum, c.is_online
            ORDER BY ae.schedule_id, c.semester, ae.day, ae.starts_at
        ");
        $stmt->execute($scheduleIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by schedule_id -> semester -> events
        $data = [
            'schedules' => [],
            'schedule_ids' => $scheduleIds
        ];

        foreach ($scheduleIds as $sid) {
            $data['schedules'][$sid] = [];
        }

        foreach ($rows as $row) {
            $schedId = (int)$row['schedule_id'];
            $sem = (int)$row['semester'];

            if (!isset($data['schedules'][$schedId][$sem])) {
                $data['schedules'][$schedId][$sem] = [];
            }

            $data['schedules'][$schedId][$sem][] = [
                'day' => (int)$row['day'],
                'start' => substr($row['starts_at'], 11, 5),
                'end' => substr($row['ends_at'], 11, 5),
                'course' => $row['coursename'],

                'room' => $row['roomcode'],
                'type' => $row['type_enum'],
                'is_online' => (bool)$row['is_online'],
                'professors' => $row['professors'],
                'assistants' => $row['assistants'],
            ];
        }

        // Fetch EXAM events (raspored kolokvijuma)
        $examScheduleId = $scheduleIds[0] ?? 0;
        $examStmt = $pdo->prepare("
            SELECT 
                ae.day,
                ae.starts_at,
                ae.ends_at,
                c.name AS coursename,
                r.code AS roomcode,
                ae.type_enum,
                c.semester
            FROM academic_event ae
            JOIN course c ON ae.course_id = c.id
            LEFT JOIN room r ON ae.room_id = r.id
            WHERE (ae.type_enum IN ('EXAM', 'COLLOQUIUM') AND ae.schedule_id = ?)
               OR ae.type_enum IN ('COLLOQUIUM_1', 'COLLOQUIUM_2')
            ORDER BY ae.starts_at ASC
        ");
        $examStmt->execute([$examScheduleId]);
        $examRows = $examStmt->fetchAll(PDO::FETCH_ASSOC);

        $data['exams'] = [];
        foreach ($examRows as $row) {
            $data['exams'][] = [
                'day' => $row['day'],
                'start' => substr($row['starts_at'], 11, 5),
                'end' => substr($row['ends_at'], 11, 5),
                'course' => $row['coursename'],
                'room' => $row['roomcode'],
                'date' => substr($row['starts_at'], 0, 10),
                'type' => $row['type_enum'],
                'semester' => (int)$row['semester']
            ];
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Greška pri čitanju rasporeda: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generatecolloquiums') {
    // Očisti sve output buffere da osiguramo čist JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    // Provera da li je korisnik ADMIN
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    try {
        // Postavi working directory na root projekta
        $projectRoot = dirname(__DIR__, 2);
        if (!is_dir($projectRoot)) {
            echo json_encode(['status' => 'error', 'message' => 'Root direktorijum projekta nije pronađen.']);
            exit;
        }
        chdir($projectRoot);

        // Putanja do Java fajlova
        $javaDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'java';
        $jarFile = $javaDir . DIRECTORY_SEPARATOR . 'postgresql-42.7.8.jar';

        // Provera da li Java fajlovi postoje
        if (!file_exists($javaDir . DIRECTORY_SEPARATOR . 'ValidacijaTermina.class')) {
            echo json_encode(['status' => 'error', 'message' => 'Java klasa ValidacijaTermina nije pronađena u: ' . $javaDir]);
            exit;
        }

        if (!file_exists($jarFile)) {
            echo json_encode(['status' => 'error', 'message' => 'PostgreSQL JDBC driver nije pronađen: ' . $jarFile]);
            exit;
        }

        // Formiranje Java komande
        $separator = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ';' : ':';
        $classpath = $javaDir . $separator . $jarFile;

        // Komanda za pokretanje Java programa - akcija generisiKolokvijume
        $command = sprintf(
            'java -cp "%s" ValidacijaTermina generisiKolokvijume 2>&1',
            $classpath
        );

        // Izvršavanje komande i hvatanje output-a
        $outputString = shell_exec($command);

        // Ako shell_exec vrati null, pokušaj sa exec
        if ($outputString === null) {
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            $outputString = implode("\n", $output);
        }

        // Funkcija za očišćavanje UTF-8 stringa
        /* Already defined inside current file scope if in same execution,
           but here cleanUtf8 is locally defined inside the if block of generateschedule
           so proper way is to define it once or redefine safely?
           Actually, PHP functions are global if not in namespace/class.
           Wait, function cleanUtf8 inside an IF block is conditionally defined.
           I should check provided context. The previous cleanUtf8 was inside `if (isset($_GET['action']) && $_GET['action'] === 'generateschedule')`.
           If I am here, that block didn't run. So I should define it or reuse it if defined.
           Safest is to check `function_exists`.
        */
        if (!function_exists('cleanUtf8')) {
            function cleanUtf8($string)
            {
                if (!mb_check_encoding($string, 'UTF-8')) {
                    $string = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string, 'UTF-8, ISO-8859-1, Windows-1252', true));
                }
                $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
                $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
                return $string;
            }
        }

        $outputString = cleanUtf8($outputString ?? '');

        // Parsiranje output-a
        $isSuccess = true; // Pretpostavljamo uspeh za kolokvijume osim ako ne vrati gresku
        $message = trim($outputString);

        // Formatiranje poruke ako je JSON ili raw text
        $message = str_replace(["\r", "\n"], " ", $message);
        $message = preg_replace('/\s+/', ' ', $message);

        echo json_encode(['status' => 'success', 'message' => $message, 'output' => $outputString], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Greška: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generateschedule') {
    // Očisti sve output buffere da osiguramo čist JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    // Provera da li je korisnik ADMIN
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    try {
        // Postavi working directory na root projekta (gde se nalazi .env fajl)
        $projectRoot = dirname(__DIR__, 2); // dva nivoa iznad public/views
        if (!is_dir($projectRoot)) {
            echo json_encode(['status' => 'error', 'message' => 'Root direktorijum projekta nije pronađen.']);
            exit;
        }
        chdir($projectRoot);

        // Putanja do Java fajlova
        $javaDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'java';
        $jarFile = $javaDir . DIRECTORY_SEPARATOR . 'postgresql-42.7.8.jar';

        // Provera da li Java fajlovi postoje
        if (!file_exists($javaDir . DIRECTORY_SEPARATOR . 'ValidacijaTermina.class')) {
            echo json_encode(['status' => 'error', 'message' => 'Java klasa ValidacijaTermina nije pronađena u: ' . $javaDir]);
            exit;
        }

        if (!file_exists($jarFile)) {
            echo json_encode(['status' => 'error', 'message' => 'PostgreSQL JDBC driver nije pronađen: ' . $jarFile]);
            exit;
        }

        // Formiranje Java komande
        // Na Windows-u koristimo ; kao separator, na Linux/Mac koristimo :
        $separator = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ';' : ':';
        $classpath = $javaDir . $separator . $jarFile;

        // Komanda za pokretanje Java programa
        // Koristimo shell_exec za bolje hvatanje output-a
        $command = sprintf(
            'java -cp "%s" ValidacijaTermina generisiKompletan 2>&1',
            $classpath
        );

        // Izvršavanje komande i hvatanje output-a
        $outputString = shell_exec($command);

        // Ako shell_exec vrati null, pokušaj sa exec
        if ($outputString === null) {
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            $outputString = implode("\n", $output);

            if ($returnCode !== 0 && empty($outputString)) {
                echo json_encode(['status' => 'error', 'message' => 'Java program nije mogao biti pokrenut. Proverite da li je Java instaliran i u PATH-u.']);
                exit;
            }
        }

        // Provera da li imamo output
        if (empty($outputString) || trim($outputString) === '') {
            echo json_encode(['status' => 'error', 'message' => 'Java program nije vratio nikakav output.']);
            exit;
        }

        // Funkcija za očišćavanje UTF-8 stringa
        function cleanUtf8($string)
        {
            // Prvo pokušaj da konvertuješ u UTF-8
            if (!mb_check_encoding($string, 'UTF-8')) {
                // Ako nije validan UTF-8, pokušaj da konvertuješ
                $string = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string, 'UTF-8, ISO-8859-1, Windows-1252', true));
            }

            // Ukloni nevalidne UTF-8 karaktere
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');

            // Ukloni kontrolne karaktere osim novih linija i tabova
            $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);

            return $string;
        }

        // Očisti output od nevalidnih UTF-8 karaktera
        $outputString = cleanUtf8($outputString);

        // Parsiranje output-a da vidimo da li je uspešno
        $isSuccess = false;
        $message = '';

        if (stripos($outputString, 'OK:') !== false) {
            $isSuccess = true;
            // Izvuci poruku nakon "OK:"
            $okPos = stripos($outputString, 'OK:');
            $message = trim(substr($outputString, $okPos + 3));
            // Uzmi samo prvu liniju poruke
            $lines = explode("\n", $message);
            $message = trim($lines[0]);
        } elseif (stripos($outputString, 'WARNING:') !== false) {
            $isSuccess = true; // Warning se smatra delimičnim uspehom
            $warningPos = stripos($outputString, 'WARNING:');
            $message = trim(substr($outputString, $warningPos + 8));
            $lines = explode("\n", $message);
            $message = trim($lines[0]);
        } elseif (stripos($outputString, 'ERROR:') !== false) {
            $errorPos = stripos($outputString, 'ERROR:');
            $message = trim(substr($outputString, $errorPos + 6));
            $lines = explode("\n", $message);
            $message = trim($lines[0]);
        } else {
            // Ako nema eksplicitne poruke, koristimo ceo output (ali ograničimo dužinu)
            $message = !empty($outputString) ? trim(substr($outputString, 0, 500)) : 'Raspored je generisan.';
            $isSuccess = true; // Pretpostavljamo uspeh ako nema eksplicitne greške
        }

        // Očisti message od potencijalnih JSON problematičnih karaktera
        $message = cleanUtf8($message);
        $message = str_replace(["\r", "\n"], " ", $message);
        $message = preg_replace('/\s+/', ' ', $message);
        $message = trim($message);

        $response = [
            'status' => $isSuccess ? 'success' : 'error',
            'message' => $message
        ];

        // Dodaj output samo ako nije previše dug (da ne pravi probleme sa JSON-om)
        if (strlen($outputString) < 10000) {
            $cleanOutput = cleanUtf8($outputString);
            $response['output'] = $cleanOutput;
        }

        // Koristi JSON_INVALID_UTF8_IGNORE flag ako je dostupan (PHP 7.2+)
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_IGNORE')) {
            $jsonFlags |= JSON_INVALID_UTF8_IGNORE;
        }

        $jsonResponse = json_encode($response, $jsonFlags);

        if ($jsonResponse === false) {
            // Ako i dalje ima problema, pokušaj da očistiš sve ne-ASCII karaktere iz poruke
            $safeMessage = preg_replace('/[^\x20-\x7E]/u', '', $message);
            if (empty($safeMessage)) {
                $safeMessage = 'Raspored je generisan (neki karakteri su uklonjeni zbog enkodiranja).';
            }
            echo json_encode(['status' => $isSuccess ? 'success' : 'error', 'message' => $safeMessage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo $jsonResponse;
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Greška: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['status' => 'error', 'message' => 'Fatalna greška: ' . $e->getMessage()]);
    }
    exit;
}


// Only ADMIN can view this page.
// Redirect others.
if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
    // Not loggedIn -> go to auth
    header('Location: ./authorization.php');
    exit;
}

if ($_SESSION['role'] !== 'ADMIN') {
    // LoggedIn but not admin -> go to professor profile
    header('Location: ./professor_panel.php');
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_profesor':
                $full_name = $_POST['full_name'];
                $email = $_POST['email'];

                try {
                    $stmt = $pdo->prepare("INSERT INTO professor (full_name, email, is_active) VALUES (?, ?, TRUE)");
                    $stmt->execute([$full_name, $email]);
                    header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju profesora: " . $e->getMessage();
                }
                break;

            case 'add_predmet':
                $name = $_POST['name'];
                $semester = $_POST['semester'];
                $code = $_POST['code'];
                $is_optional = isset($_POST['is_optional']) ? 1 : 0;

                try {
                    $stmt = $pdo->prepare("INSERT INTO course (name, semester, code, is_optional, is_active) 
                                          VALUES (?, ?, ?, ?, TRUE)");
                    $stmt->execute([$name, $semester, $code, $is_optional]);
                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju predmeta: " . $e->getMessage();
                }
                break;

            case 'add_sala':
                $code = $_POST['code'];
                $capacity = $_POST['capacity'];
                $is_computer_lab = isset($_POST['is_computer_lab']) ? 1 : 0;

                try {
                    $stmt = $pdo->prepare("INSERT INTO room (code, capacity, is_computer_lab, is_active) 
                                          VALUES (?, ?, ?, TRUE)");
                    $stmt->execute([$code, $capacity, $is_computer_lab]);
                    header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno dodata."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju sale: " . $e->getMessage();
                }
                break;

            case 'add_dogadjaj':
                $course_id = $_POST['course_id'];
                $professor_id = $_POST['professor_id'];
                $type = $_POST['type'];
                $starts_at = $_POST['starts_at'];
                $ends_at = $_POST['ends_at'];
                $is_online = isset($_POST['is_online']) ? 1 : 0;
                $room_id = $is_online ? null : $_POST['room_id'];
                $notes = $_POST['notes'];
                $is_published = isset($_POST['is_published']) ? 1 : 0;

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO academic_event 
                                          (course_id, created_by_professor, type_enum, starts_at, ends_at, 
                                           is_online, room_id, notes, is_published, locked_by_admin) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)");
                    $stmt->execute([$course_id, $professor_id, $type, $starts_at, $ends_at,
                        $is_online, $room_id, $notes, $is_published]);

                    $event_id = $pdo->lastInsertId();

                    // Dodavanje veze događaj-profesor
                    $stmt = $pdo->prepare("INSERT INTO event_professor (event_id, professor_id) 
                                          VALUES (?, ?)");
                    $stmt->execute([$event_id, $professor_id]);

                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Greška pri dodavanju događaja: " . $e->getMessage();
                }
                break;

            case 'assign_professor':
                // Pridruživanje profesora predmetu
                $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
                $professor_id = isset($_POST['professor_id']) ? (int)$_POST['professor_id'] : 0;
                $is_assistant = isset($_POST['is_assistant']) ? 1 : 0;

                if ($course_id <= 0 || $professor_id <= 0) {
                    $error = "Morate izabrati i predmet i profesora.";
                    break;
                }

                try {
                    // Provjeri postojeće veze za predmet
                    $stmt = $pdo->prepare("SELECT professor_id, is_assistant FROM course_professor WHERE course_id = ?");
                    $stmt->execute([$course_id]);
                    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Ako je profesor već pridružen - zabranjeno
                    foreach ($existing as $ex) {
                        if ((int)$ex['professor_id'] === $professor_id) {
                            $error = "Odabrani profesor je već pridružen ovom predmetu.";
                            break 2;
                        }
                    }

                    // Ubaci vezu (role_enum ima podrazumijevanu vrijednost u bazi)
                    $stmt = $pdo->prepare("INSERT INTO course_professor (course_id, professor_id, is_assistant) VALUES (?, ?, ?)");
                    $stmt->execute([$course_id, $professor_id, $is_assistant]);

                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Profesor je uspješno pridružen predmetu."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri povezivanju profesora i predmeta: " . $e->getMessage();
                }
                break;


            // Brisanje i deaktiviranje
            case 'delete_professor':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE professor SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju profesora: " . $e->getMessage();
                    }
                }
                break;

            case 'activate_professor':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE professor SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju profesora: " . $e->getMessage();
                    }
                }
                break;

            case 'activate_predmet':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE course SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju predmeta: " . $e->getMessage();
                    }
                }
                break;

            case 'delete_predmet':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE course SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju predmeta: " . $e->getMessage();
                    }
                }
                break;

            case 'activate_sala':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE room SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno aktivirana."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju sale: " . $e->getMessage();
                    }
                }
                break;

            case 'delete_sala':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE room SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno deaktivirana."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju sale: " . $e->getMessage();
                    }
                }
                break;

            case 'delete_dogadjaj':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("DELETE FROM event_professor WHERE event_id = ?");
                        $stmt->execute([$id]);

                        $stmt = $pdo->prepare("DELETE FROM academic_event WHERE id = ?");
                        $stmt->execute([$id]);

                        $pdo->commit();
                        header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno obrisan."));
                        exit;
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $error = "Greška pri brisanju događaja: " . $e->getMessage();
                    }
                }
                break;

            case 'add_academic_year':
                $year_label = $_POST['year_label'];
                $winter_start = $_POST['winter_semester_start'];
                $summer_start = $_POST['summer_semester_start'];

                try {
                    $stmt = $pdo->prepare("INSERT INTO academic_year (year_label, winter_semester_start, summer_semester_start, is_active) VALUES (?, ?, ?, TRUE)");
                    $stmt->execute([$year_label, $winter_start, $summer_start]);
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno dodata."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju akademske godine: " . $e->getMessage();
                }
                break;

            case 'save_occupancy':
                $selections = json_decode($_POST['selections'] ?? '[]', true);
                $faculty_code = $_POST['faculty_code'] ?? '';
                $acad_year_id = (int)($_POST['academic_year_id'] ?? 0);

                if (empty($selections) || $acad_year_id === 0) {
                    $error = "Nevažeći podaci za snimanje zauzetosti.";
                    break;
                }

                $ret = $occupancyService->saveOccupancy($acad_year_id, $selections, $faculty_code);
                if ($ret['success']) {
                    header("Location: ?page=zauzetost&success=1&message=" . urlencode("Zauzetost sala je uspješno ažurirana."));
                    exit;
                } else {
                    $error = "Greška pri snimanju zauzetosti sala: " . $ret['error'];
                }
                break;

            // ****  update  ****

            case 'delete_academic_year':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("DELETE FROM academic_year WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno obrisana."));
                        exit;
                    } catch (PDOException $e) {
                        // Check for foreign key violation
                        if ($e->getCode() == '23503') {
                            $error = "Greška: Ne možete obrisati ovu akademsku godinu jer postoje podaci vezani za nju. Probajte je deaktivirati.";
                        } else {
                            $error = "Greška pri brisanju akademske godine: " . $e->getMessage();
                        }
                    }
                }
                break;

            case 'update_academic_year':
                $id = (int)$_POST['year_id'];
                $year_label = $_POST['year_label'];
                $winter_start = $_POST['winter_semester_start'];
                $summer_start = $_POST['summer_semester_start'];
                $is_active = isset($_POST['is_active']) ? true : false;

                try {
                    $stmt = $pdo->prepare("UPDATE academic_year SET year_label = ?, winter_semester_start = ?, summer_semester_start = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$year_label, $winter_start, $summer_start, $is_active ? 'TRUE' : 'FALSE', $id]);
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno ažurirana."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri ažuriranju akademske godine: " . $e->getMessage();
                }
                break;

            case 'update_profesor':

                $fields = [];
                $params = [];

                if (isset($_POST['full_name'])) {
                    $fields[] = "full_name = ?";
                    $params[] = $_POST['full_name'];
                }
                if (isset($_POST['email'])) {
                    $fields[] = "email = ?";
                    $params[] = $_POST['email'];
                }
                if (!isset($_POST['profesor_id'])) {
                    throw new Exception("Profesorov ID nije validan.");
                }

                $params[] = (int)$_POST['profesor_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $stmt = $pdo->prepare("UPDATE professor SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri ažuriranju profesora: " . $e->getMessage();
                }
                break;

            case 'update_predmet':
                $fields = [];
                $params = [];

                if (isset($_POST['name'])) {
                    $fields[] = "name = ?";
                    $params[] = $_POST['name'];
                }
                if (isset($_POST['semester'])) {
                    $fields[] = "semester = ?";
                    $params[] = $_POST['semester'];
                }
                if (isset($_POST['code'])) {
                    $fields[] = "code = ?";
                    $params[] = $_POST['code'];
                }

                // Uvijek ažuriramo checkbox jer HTML forme ne šalju unchecked vrijednosti
                $fields[] = "is_optional = ?";
                $params[] = isset($_POST['is_optional']) ? 1 : 0;

                if (!isset($_POST['course_id'])) {
                    throw new Exception("Course ID nije validan.");
                }

                $params[] = (int)$_POST['course_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $stmt = $pdo->prepare("UPDATE course SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    // Ako su poslata prof_assignments polja, obraditi ih (zamijeni postojeće veze)
                    if (isset($_POST['prof_assignments'])) {
                        $raw = $_POST['prof_assignments'];
                        $assignments = json_decode($raw, true);
                        if (!is_array($assignments)) {
                            throw new Exception('Neispravan format podataka o profesorima.');
                        }

                        // Basic validation
                        $count = count($assignments);

                        $ids = [];
                        foreach ($assignments as $a) {
                            // accept either professor_id or id (frontend may send {id:...})
                            if (!isset($a['professor_id']) && !isset($a['id'])) throw new Exception('Nedostaje professor_id.');
                            $pid = (int)($a['professor_id'] ?? $a['id']);
                            if ($pid <= 0) throw new Exception('Neispravan professor_id.');
                            if (in_array($pid, $ids)) throw new Exception('Isti profesor ne može biti u više uloga.');
                            $ids[] = $pid;
                        }

                        // Zamijeni veze u transakciji
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("DELETE FROM course_professor WHERE course_id = ?");
                        $stmt->execute([(int)$_POST['course_id']]);

                        if ($count > 0) {
                            $ins = $pdo->prepare("INSERT INTO course_professor (course_id, professor_id, is_assistant) VALUES (?, ?, ?)");
                            foreach ($assignments as $a) {
                                $pid = (int)($a['professor_id'] ?? $a['id']);
                                $ins->execute([(int)$_POST['course_id'], $pid, isset($a['is_assistant']) && $a['is_assistant'] ? 1 : 0]);
                            }
                        }
                        $pdo->commit();
                    }
                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Greška pri ažuriranju predmeta: " . $e->getMessage();
                }
                break;

            case 'update_sala':
                $fields = [];
                $params = [];

                if (isset($_POST['code'])) {
                    $fields[] = "code = ?";
                    $params[] = $_POST['code'];
                }
                if (isset($_POST['capacity'])) {
                    $fields[] = "capacity = ?";
                    $params[] = $_POST['capacity'];
                }

                // Uvijek ažuriramo checkbox jer HTML forme ne šalju unchecked vrijednosti
                $fields[] = "is_computer_lab = ?";
                $params[] = isset($_POST['is_computer_lab']) ? 1 : 0;

                if (!isset($_POST['sala_id'])) {
                    throw new Exception("Sala ID nije validan.");
                }

                $params[] = (int)$_POST['sala_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }

                try {
                    $stmt = $pdo->prepare("UPDATE room SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno ažurirana."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri ažuriranju sale: " . $e->getMessage();
                }
                break;

            case 'update_dogadjaj':
                $fields = [];
                $params = [];

                $fields2 = [];
                $params2 = [];

                if (isset($_POST['course_id'])) {
                    $fields[] = "course_id = ?";
                    $params[] = $_POST['course_id'];
                }
                if (isset($_POST['professor_id'])) {
                    $fields2[] = "professor_id = ?";
                    $params2[] = $_POST['professor_id'];
                }
                if (isset($_POST['type'])) {
                    // DB column is 'type_enum'
                    $fields[] = "type_enum = ?";
                    $params[] = $_POST['type'];
                }
                if (isset($_POST['starts_at'])) {
                    $fields[] = "starts_at = ?";
                    $params[] = $_POST['starts_at'];
                }
                if (isset($_POST['ends_at'])) {
                    $fields[] = "ends_at = ?";
                    $params[] = $_POST['ends_at'];
                }
                if (isset($_POST['is_online'])) {
                    $fields[] = "is_online = ?";
                    $params[] = isset($_POST['is_online']) ? 1 : 0;
                }
                if (isset($_POST['room_id'])) {
                    $fields[] = "room_id = ?";
                    $params[] = $_POST['room_id'];
                }
                if (isset($_POST['notes'])) {
                    $fields[] = "notes = ?";
                    $params[] = $_POST['notes'];
                }
                if (isset($_POST['is_published'])) {
                    $fields[] = "is_published = ?";
                    $params[] = isset($_POST['is_published']) ? 1 : 0;
                }

                if (!isset($_POST['dogadjaj_id'])) {
                    throw new Exception("Dogadjaj ID nije validan.");
                }
                // dogadjaj id
                $params[] = (int)$_POST['dogadjaj_id'];
                // If frontend didn't provide event_professor_id, try to lookup; if none, we'll insert later.
                $need_insert_event_prof = false;
                if (!isset($_POST['event_professor_id'])) {
                    try {
                        $stmtEp = $pdo->prepare("SELECT id FROM event_professor WHERE event_id = ? LIMIT 1");
                        $stmtEp->execute([(int)$_POST['dogadjaj_id']]);
                        $epRow = $stmtEp->fetch(PDO::FETCH_ASSOC);
                        if ($epRow && isset($epRow['id'])) {
                            $_POST['event_professor_id'] = (int)$epRow['id'];
                        } else {
                            // mark that we need to insert a new event_professor row after updating the event
                            $need_insert_event_prof = true;
                        }
                    } catch (PDOException $e) {
                        // DB error while looking up — mark for insert (so we attempt to create row if possible)
                        $need_insert_event_prof = true;
                    }
                }

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("UPDATE academic_event SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);

                    $event_id = (int)$_POST['dogadjaj_id'];;

                    // Handle event_professor relation: update existing or insert new
                    if (isset($_POST['event_professor_id']) && (int)$_POST['event_professor_id'] > 0) {
                        // update the existing relation's professor if professor_id provided
                        if (isset($_POST['professor_id'])) {
                            $upd = $pdo->prepare("UPDATE event_professor SET professor_id = ? WHERE id = ?");
                            $upd->execute([(int)$_POST['professor_id'], (int)$_POST['event_professor_id']]);
                        }
                    } else {
                        // no existing relation id — replace any existing relation for this event with new one (if professor_id present)
                        if (isset($_POST['professor_id'])) {
                            // remove any existing relations for safety
                            $del = $pdo->prepare("DELETE FROM event_professor WHERE event_id = ?");
                            $del->execute([$event_id]);
                            // insert new relation
                            $ins = $pdo->prepare("INSERT INTO event_professor (event_id, professor_id) VALUES (?, ?)");
                            $ins->execute([$event_id, (int)$_POST['professor_id']]);
                        }
                    }

                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Greška pri ažuriranju događaja: " . $e->getMessage();
                }
                break;

            // --------- user_account management ---------
            case 'add_account':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'USER';
                $professor_id = isset($_POST['professor_id']) && is_numeric($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;

                if ($username === '' || $password === '') {
                    $error = 'Username i lozinka su obavezni.';
                    break;
                }

                try {
                    // check unique username
                    $stmt = $pdo->prepare("SELECT id FROM user_account WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error = 'Korisničko ime već postoji.';
                        break;
                    }

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO user_account (username, password_hash, role_enum, is_active, professor_id) VALUES (?, ?, ?, TRUE, ?)");
                    $stmt->execute([$username, $password_hash, $role, $professor_id]);

                    header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Greška pri dodavanju korisnika: ' . $e->getMessage();
                }
                break;

            case 'update_account':
                if (!isset($_POST['account_id']) || !is_numeric($_POST['account_id'])) {
                    $error = 'Neispravan ID korisnika.';
                    break;
                }
                $accId = (int)$_POST['account_id'];
                $fields = [];
                $params = [];

                if (isset($_POST['username']) && trim($_POST['username']) !== '') {
                    $fields[] = 'username = ?';
                    $params[] = trim($_POST['username']);
                }
                if (isset($_POST['password']) && $_POST['password'] !== '') {
                    $fields[] = 'password_hash = ?';
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                if (isset($_POST['role'])) {
                    $fields[] = 'role_enum = ?';
                    $params[] = $_POST['role'];
                }
                if (isset($_POST['is_active'])) {
                    $fields[] = 'is_active = ?';
                    $params[] = isset($_POST['is_active']) && $_POST['is_active'] ? 1 : 0;
                }
                if (array_key_exists('professor_id', $_POST)) {
                    $prof = is_numeric($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
                    $fields[] = 'professor_id = ?';
                    $params[] = $prof;
                }

                if (empty($fields)) {
                    $error = 'Nema podataka za ažuriranje.';
                    break;
                }

                $params[] = $accId;
                try {
                    $stmt = $pdo->prepare('UPDATE user_account SET ' . implode(', ', $fields) . ' WHERE id = ?');
                    $stmt->execute($params);
                    header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Greška pri ažuriranju korisnika: ' . $e->getMessage();
                }
                break;

            case 'delete_account':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE user_account SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = 'Greška pri deaktiviranju korisnika: ' . $e->getMessage();
                    }
                }
                break;

            case 'activate_account':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE user_account SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = 'Greška pri aktiviranju korisnika: ' . $e->getMessage();
                    }
                }
                break;

            case 'set_deadline':
                $deadline_date = $_POST['deadline_date'];

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO config (\"key\", value) 
                        VALUES ('schedule_deadline', ?) 
                        ON CONFLICT (\"key\") DO UPDATE SET value = ?
                    ");
                    $stmt->execute([$deadline_date, $deadline_date]);

                    header("Location: ?page=profesori&success=1&message=" . urlencode("Uspješno postavljen deadline za izbor sedmice kolokvijuma."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri postavljanju deadlina: " . $e->getMessage();
                }
                break;

            // --------- end user_account management ---------
        }
    }
}
?>

<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Raspored Ispita</title>
    <link rel="stylesheet" href="../assets/css/admin.css"/>
    <link rel="stylesheet" href="../assets/css/base.css"/>
    <link rel="stylesheet" href="../assets/css/fields.css"/>
    <link rel="stylesheet" href="../assets/css/colors.css"/>
    <link rel="stylesheet" href="../assets/css/stacks.css"/>
    <link rel="stylesheet" href="../assets/css/tabs.css"/>
    <link rel="stylesheet" href="../assets/css/table.css"/>
    <link rel="stylesheet" href="../assets/css/occupancy.css"/>

    <?php // expose active professors to JS before admin.js loads ?>
    <script>
        window.adminData = window.adminData || {};
        window.adminData.professors = <?php
        try {
            $stmtForJS = $pdo->query("SELECT id, full_name, email FROM professor WHERE is_active = TRUE ORDER BY full_name");
            $rows = $stmtForJS->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            echo '[]';
        }
        ?> ||
        [];
        <?php
        // expose schedule_locked config and locked schedule ids
        try {
            $schedLockedVal = $pdo->query("SELECT value FROM config WHERE \"key\" = 'schedule_locked'")->fetchColumn();
            $schedLocked = false;
            if ($schedLockedVal !== false && $schedLockedVal !== null) {
                $schedLocked = in_array(strval($schedLockedVal), ['1', 'true', 'TRUE', 't'], true);
            }
            $lockedIds = [];
            if ($schedLocked) {
                $lockedStmt = $pdo->query("SELECT DISTINCT schedule_id FROM academic_event WHERE locked_by_admin = TRUE AND schedule_id IS NOT NULL ORDER BY schedule_id DESC");
                $lockedIds = $lockedStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            echo "window.adminData.schedule_locked = " . ($schedLocked ? 'true' : 'false') . ";\n";
            echo "window.adminData.locked_schedule_ids = " . json_encode(array_values($lockedIds), JSON_UNESCAPED_UNICODE) . ";\n";
        } catch (PDOException $e) {
            echo "window.adminData.schedule_locked = false;\nwindow.adminData.locked_schedule_ids = [];\n";
        }
        ?>
        window.adminData.faculties = ["FIT", "FEB", "MTS", "PF", "FSJ", "FVU"];
    </script>
    <script src="../assets/js/jspdf.umd.min.js"></script>
    <script src="../assets/js/jspdf.plugin.autotable.min.js"></script>
    <script src="../assets/js/DejaVuSans-normal.js"></script>
    <script src="../assets/js/admin.js" defer></script>

    <header>
        <h1>Admin Panel</h1>
        <nav>
            <ul>
                <li><a href="index.php">Pocetna</a></li>
                <li><a href="?page=profesori">Profesori</a></li>
                <li><a href="?page=predmeti">Predmeti</a></li>
                <li><a href="?page=sale">Sale</a></li>
                <li><a href="?page=account">Nalog</a></li>
                <li><a href="?page=zauzetost">Zauzetost sala</a></li>
                <li><a href="?page=dogadjaji">Kalendar</a></li>
                <li><a href="?page=logout">Rasporedi</a></li>
                <li><a href="logout.php">Odjavi se</a></li>
            </ul>
        </nav>
    </header>

    <main>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success">
                <?php
                if (isset($_GET['message'])) {
                    echo htmlspecialchars($_GET['message']);
                } else {
                    echo "Operacija je uspješno izvršena!";
                }
                ?>
            </div>
        <?php endif; ?>

        <?php
        $page = isset($_GET['page']) ? $_GET['page'] : 'pocetna';

        switch ($page) {
        case 'profesori':
        ?>
        <h2>Upravljanje Profesorima</h2>
        <button class="action-button add-button" onclick="toggleForm('profesorForm')">+ Dodaj Profesora</button>
        <button class="action-button add-button deadline-button" onclick="toggleForm('deadlineForm')">+ Deadline unosa
            kolokvijuma
        </button>

        <div id="profesorForm" class="form-container" style="display: none">
            <h3>Novi profesor</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_profesor">

                <label for="full_name">Ime i prezime:</label>
                <input type="text" id="full_name" name="full_name" required>

                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>

                <button type="submit">Sačuvaj</button>
            </form>
        </div>

        <div id="deadlineForm" class="form-container" style="display:none">
            <h3>Postavi deadline za izbor sedmice kolokvijuma</h3>
            <form method="post">
                <input type="hidden" name="action" value="set_deadline">
                <input type="date" id="deadline_date" name="deadline_date" value="<?php echo $current_deadline; ?>"
                       required>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit">Sačuvaj</button>
                </div>
            </form>
        </div>


        <table border="1" cellpadding="5">
            <tr>
                <th>ID</th>
                <th>Ime i prezime</th>
                <th>Email</th>
                <th>Status</th>
                <th>Akcije</th>
            </tr>
            <?php

            try {
                $stmt = $pdo->query("SELECT * FROM professor ORDER BY id");
                while ($row = $stmt->fetch()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                    echo "<td>" . ($row['is_active'] ? 'Aktivan' : 'Neaktivan') . "</td>";
                    echo "<td>";
                    echo "<button class='action-button edit-button' data-entity='profesor' data-id='" . $row['id'] . "' data-full_name='" . htmlspecialchars($row['full_name'], ENT_QUOTES) . "' data-email='" . htmlspecialchars($row['email'], ENT_QUOTES) . "'>Uredi</button>";

                    if ($row['is_active']) {
                        echo "<form id='delete-profesor-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='delete_professor'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_professor', 'profesor')\">Deaktiviraj</button>
                        </form>";
                    } else {
                        echo "<form id='activate-profesor-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='activate_professor'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_professor', 'profesor')\">Aktiviraj</button>
                        </form>";
                    }

                    echo "</td>";
                    echo "</tr>";
                }

            } catch (PDOException $e) {
                echo "<tr><td colspan='5'>Greška pri dohvaćanju profesora: " . $e->getMessage() . "</td></tr>";
            }
            echo "</table>";
            break;
            case 'predmeti':
                ?>

                <h2>Upravljanje Predmetima</h2>

                <button class="action-button add-button" onclick="toggleForm('predmetForm')">
                    + Dodaj Predmet
                </button>

                <button class="action-button add-button" onclick="toggleForm('assignForm')">
                    + Pridruži Profesora
                </button>

                <!-- ===== Pridruži profesora ===== -->
                <div id="assignForm" class="form-container" style="display:none">
                    <h3>Pridruži profesora predmetu</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="assign_professor">

                        <label>Predmet:</label>
                        <select name="course_id" required>
                            <option value="">-- Odaberite --</option>
                            <?php
                            $stmt = $pdo->query("SELECT id, name, code FROM course WHERE is_active = TRUE ORDER BY name");
                            while ($c = $stmt->fetch()):
                                ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label>Profesor:</label>
                        <select name="professor_id" required>
                            <option value="">-- Odaberite --</option>
                            <?php
                            $stmt = $pdo->query("SELECT id, full_name FROM professor WHERE is_active = TRUE ORDER BY full_name");
                            while ($p = $stmt->fetch()):
                                ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label>
                            <input type="checkbox" name="is_assistant"> Asistent
                        </label>

                        <button type="submit">Sačuvaj</button>
                    </form>
                </div>

                <!-- ===== Novi predmet ===== -->
                <div id="predmetForm" class="form-container" style="display:none">
                    <h3>Novi predmet</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_predmet">

                        <label>Naziv:</label>
                        <input type="text" name="name" required>

                        <label>Šifra:</label>
                        <input type="text" name="code" required>

                        <label>Semestar:</label>
                        <input type="number" name="semester" min="1" max="6" required>

                        <label>
                            <input type="checkbox" name="is_optional"> Izborni
                        </label>

                        <button type="submit">Sačuvaj</button>
                    </form>
                </div>

                <?php
                $stmt = $pdo->query("
    SELECT 
        c.id AS course_id,
        c.name,
        c.code,
        c.semester,
        c.is_optional,
        c.is_active,
        cp.professor_id,
        cp.is_assistant,
        p.full_name
    FROM course c
    LEFT JOIN course_professor cp ON cp.course_id = c.id
    LEFT JOIN professor p ON p.id = cp.professor_id
    ORDER BY c.id
");

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $courses = [];

                foreach ($rows as $r) {
                    $cid = (int)$r['course_id'];
                    if (!isset($courses[$cid])) {
                        $courses[$cid] = [
                            'id' => $cid,
                            'name' => $r['name'],
                            'code' => $r['code'],
                            'semester' => $r['semester'],
                            'is_optional' => (int)$r['is_optional'],
                            'is_active' => (int)$r['is_active'],
                            'professors' => []
                        ];
                    }

                    if ($r['professor_id']) {
                        $courses[$cid]['professors'][] = [
                            'id' => (int)$r['professor_id'],
                            'name' => $r['full_name'],
                            'is_assistant' => (int)$r['is_assistant']
                        ];
                    }
                }
                ?>

                <table border="1" cellpadding="5">
                    <tr>
                        <th>ID</th>
                        <th>Naziv</th>
                        <th>Šifra</th>
                        <th>Semestar</th>
                        <th>Obavezni</th>
                        <th>Profesori</th>
                        <th>Status</th>
                        <th>Akcije</th>
                    </tr>

                    <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= htmlspecialchars($c['code']) ?></td>
                            <td><?= $c['semester'] ?></td>
                            <td><?= $c['is_optional'] ? 'Ne' : 'Da' ?></td>

                            <td>
                                <?= $c['professors']
                                    ? implode(', ', array_map(
                                        fn($p) => htmlspecialchars($p['name']) . ($p['is_assistant'] ? ' (A)' : ''),
                                        $c['professors']
                                    ))
                                    : '<em>Nema</em>' ?>
                            </td>

                            <td><?= $c['is_active'] ? 'Aktivan' : 'Neaktivan' ?></td>

                            <td>
                                <button class="action-button edit-button"
                                        data-entity="predmet"
                                        data-id="<?= $c['id'] ?>"
                                        data-name="<?= htmlspecialchars($c['name']) ?>"
                                        data-code="<?= htmlspecialchars($c['code']) ?>"
                                        data-semester="<?= $c['semester'] ?>"
                                        data-is_optional="<?= $c['is_optional'] ?>"
                                        data-professors="<?= htmlspecialchars(json_encode($c['professors'])) ?>">
                                    Uredi
                                </button>
                                <?php if ($c['is_active']): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="delete_predmet">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="action-button delete-button">Deaktiviraj</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="activate_predmet">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="action-button activation-button">Aktiviraj</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php
                break;








            case 'sale':
            ?>

            <h2>Upravljanje Salama</h2>
            <button class="action-button add-button" onclick="toggleForm('salaForm')">+ Dodaj Salu</button>

            <div id="salaForm" class="form-container" style='display: none'>
                <h3>Nova sala</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_sala">

                    <label for="code">Oznaka sale:</label>
                    <input type="text" id="code" name="code" required>

                    <label for="capacity">Kapacitet:</label>
                    <input type="number" id="capacity" name="capacity" min="1" required>

                    <label for="is_computer_lab">Računarska sala:</label>
                    <input type="checkbox" id="is_computer_lab" name="is_computer_lab">

                    <button type="submit">Sačuvaj</button>
                </form>
            </div>

            <table border="1" cellpadding="5">
                <tr>
                    <th>ID</th>
                    <th>Oznaka</th>
                    <th>Kapacitet</th>
                    <th>Tip</th>
                    <th>Status</th>
                    <th>Akcije</th>
                </tr>
                <?php

                try {
                    $stmt = $pdo->query("SELECT * FROM room ORDER BY code");
                    while ($row = $stmt->fetch()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['code']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['capacity']) . "</td>";
                        echo "<td>" . ($row['is_computer_lab'] ? 'Računarska' : 'Standardna') . "</td>";
                        echo "<td>" . ($row['is_active'] ? 'Aktivna' : 'Neaktivna') . "</td>";
                        echo "<td>";
                        echo "<button class='action-button edit-button' data-entity='sala' data-id='" . $row['id'] . "' data-code='" . htmlspecialchars($row['code'], ENT_QUOTES) . "' data-capacity='" . htmlspecialchars($row['capacity'], ENT_QUOTES) . "' data-is_computer_lab='" . ($row['is_computer_lab'] ? '1' : '0') . "'>Uredi</button>";

                        // Ako je sala neaktivna ne moze imati deaktiviraj dugme
                        if ($row['is_active']) {
                            echo "<form id='delete-sala-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='delete_sala'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_sala', 'salu')\">Deaktiviraj</button>
                        </form>";
                        } else {
                            echo "<form id='activate-sala-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='activate_sala'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_sala', 'salu')\">Aktiviraj</button>
                        </form>";
                        }

                        echo "</td>";
                        echo "</tr>";
                    }
                } catch (PDOException $e) {
                    echo "<tr><td colspan='6'>Greška pri dohvaćanju sala: " . $e->getMessage() . "</td></tr>";
                }
                echo "</table>";
                break;
                case 'account':
                    ?>

                    <h2>Upravljanje Nalozima</h2>
                    <button class="action-button add-button" onclick="toggleForm('accountForm')">+ Dodaj Nalog</button>

                    <div id="accountForm" class="form-container" style="display: none">
                        <h3>Novi nalog</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="add_account">

                            <label for="username">Korisničko ime:</label>
                            <input type="text" id="username" name="username" required>

                            <label for="password">Lozinka:</label>
                            <input type="password" id="password" name="password" required>

                            <label for="role">Uloga:</label>
                            <select id="role" name="role">
                                <option value="ADMIN">ADMIN</option>
                                <option value="PROFESSOR">PROFESSOR</option>
                                <option value="USER">USER</option>
                            </select>

                            <label for="professor_id">Povezan profesor (opcionalno):</label>
                            <select id="professor_id" name="professor_id">
                                <option value="">-- Nema --</option>
                                <?php
                                try {
                                    $stmt = $pdo->query("SELECT id, full_name, email FROM professor WHERE is_active = TRUE ORDER BY full_name");
                                    while ($p = $stmt->fetch()) {
                                        echo "<option value='" . $p['id'] . "'>" . htmlspecialchars($p['full_name']) . " (" . htmlspecialchars($p['email']) . ")</option>";
                                    }
                                } catch (PDOException $e) {
                                    echo "<option value=''>Greška pri dohvaćanju profesora</option>";
                                }
                                ?>
                            </select>

                            <button type="submit">Sačuvaj</button>
                        </form>
                    </div>

                    <table border="1" cellpadding="5">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Profesor</th>
                            <th>Status</th>
                            <th>Akcije</th>
                        </tr>
                        <?php
                        try {
                            // Sada takođe biramo email povezane profesorke
                            $stmt = $pdo->query("SELECT ua.*, p.full_name as professor_name, p.email as professor_email FROM user_account ua LEFT JOIN professor p ON ua.professor_id = p.id ORDER BY ua.id");
                            while ($row = $stmt->fetch()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['role_enum']) . "</td>";
                                // Pokazujemo ime profesora i, ako postoji, njegov email
                                $profDisplay = htmlspecialchars($row['professor_name']);
                                if (!empty($row['professor_email'])) {
                                    $profDisplay .= ' (' . htmlspecialchars($row['professor_email']) . ')';
                                }
                                echo "<td>" . $profDisplay . "</td>";
                                echo "<td>" . ($row['is_active'] ? 'Aktivan' : 'Neaktivan') . "</td>";
                                echo "<td>";
                                // Edit button: rely on admin.js generic edit handler
                                $dataAttr = htmlspecialchars(json_encode(['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role_enum'], 'professor_id' => $row['professor_id'], 'is_active' => $row['is_active']]), ENT_QUOTES);
                                echo "<button class='action-button edit-button' data-entity='account' data-payload='" . $dataAttr . "'>Uredi</button> ";

                                if ($row['is_active']) {
                                    echo "<form method='post' action='{$_SERVER['PHP_SELF']}' style='display:inline-block; margin-left:2px;'>";
                                    echo "<input type='hidden' name='action' value='delete_account'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo "<button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_account', 'nalog')\">Deaktiviraj</button>";
                                    echo "</form>";
                                } else {
                                    // account is inactive -> show activate button
                                    echo "<form method='post' style='display:inline-block; margin-left:2px;' action='{$_SERVER['PHP_SELF']}'>";
                                    echo "<input type='hidden' name='action' value='activate_account'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo " <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_account', 'nalog')\">Aktiviraj</button>";
                                    echo "</form>";
                                }

                                echo "</td>";
                                echo "</tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='6'>Greška pri dohvaćanju naloga: " . $e->getMessage() . "</td></tr>";
                        }
                        ?>

                    </table>

                    <?php
                    break;

                case 'dogadjaji':
                    ?>
                    <h2>Upravljanje Kalendarom i Događajima</h2>

                    <!-- Sekcija za Akademsku godinu -->
                    <div style="margin-bottom: 40px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
                        <h3>Akademske Godine</h3>
                        <p>Definišite početke zimskog i ljetnjeg semestra za svaku akademsku godinu.</p>

                        <button class="action-button add-button" onclick="toggleForm('academicYearForm')">+ Nova
                            akademska godina
                        </button>

                        <div id="academicYearForm" class="form-container" style="display: none; margin-top: 15px;">
                            <form method="post" style="max-width: 500px;">
                                <input type="hidden" name="action" value="add_academic_year">

                                <label for="year_label" style="display:block; margin-bottom:5px;">Naziv godine (npr.
                                    2025/2026):</label>
                                <input type="text" id="year_label" name="year_label" required placeholder="YYYY/YYYY"
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <label for="winter_semester_start" style="display:block; margin-bottom:5px;">Početak
                                    zimskog semestra:</label>
                                <input type="date" id="winter_semester_start" name="winter_semester_start" required
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <label for="summer_semester_start" style="display:block; margin-bottom:5px;">Početak
                                    ljetnjeg semestra:</label>
                                <input type="date" id="summer_semester_start" name="summer_semester_start" required
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <button type="submit" class="btn btn-primary"
                                        style="background: var(--accent); color: white; border: none; padding: 10px 20px; cursor: pointer;">
                                    Sačuvaj
                                </button>
                            </form>
                        </div>

                        <table border="1" cellpadding="5"
                               style="margin-top: 20px; width: 100%; border-collapse: collapse;">
                            <tr style="background: #f4f4f4; color: #333;">
                                <th>ID</th>
                                <th>Naziv</th>
                                <th>Zimski semestar (Start)</th>
                                <th>Ljetnji semestar (Start)</th>
                                <th>Aktivan</th>
                                <th>Akcije</th>
                            </tr>
                            <?php
                            try {
                                $stmt = $pdo->query("SELECT * FROM academic_year ORDER BY id DESC");
                                while ($row = $stmt->fetch()) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['year_label']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['winter_semester_start']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['summer_semester_start']) . "</td>";
                                    echo "<td>" . ($row['is_active'] ? 'DA' : 'NE') . "</td>";
                                    echo "<td>";
                                    echo "<button class='action-button edit-button' data-entity='academic_year' data-id='" . $row['id'] . "' data-year_label='" . htmlspecialchars($row['year_label'], ENT_QUOTES) . "' data-winter_semester_start='" . htmlspecialchars($row['winter_semester_start'], ENT_QUOTES) . "' data-summer_semester_start='" . htmlspecialchars($row['summer_semester_start'], ENT_QUOTES) . "' data-is_active='" . ($row['is_active'] ? 'true' : 'false') . "'>Uredi</button> ";

                                    echo "<form method='post' action='{$_SERVER['PHP_SELF']}' style='display:inline-block; margin-left:2px;'>";
                                    echo "<input type='hidden' name='action' value='delete_academic_year'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo "<button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_academic_year', 'akademsku godinu')\">Obriši</button>";
                                    echo "</form>";

                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='6'>Greška: " . $e->getMessage() . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>

                    <!-- Sekcija: Predmeti i sedmice kolokvijuma -->
                    <div style="margin-bottom: 40px;">
                        <h3>Predmeti i sedmice kolokvijuma</h3>
                        <p>Pregled svih predmeta sa povezanim profesorima/asistentima i postavljenim sedmicama
                            kolokvijuma.</p>

                        <table border="1" cellpadding="5"
                               style="margin-top: 20px; width: 100%; border-collapse: collapse;">
                            <tr style="background: #f4f4f4; color: #333;">
                                <th>Predmet</th>
                                <th>Profesori</th>
                                <th>Asistenti</th>
                                <th>Kolokvijum 1</th>
                                <th>Kolokvijum 2</th>
                            </tr>
                            <?php
                            try {
                                $stmtYear = $pdo->query("SELECT winter_semester_start, summer_semester_start FROM academic_year WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
                                $academicYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

                                $winterStart = $academicYear && $academicYear['winter_semester_start'] ? strtotime($academicYear['winter_semester_start']) : null;
                                $summerStart = $academicYear && $academicYear['summer_semester_start'] ? strtotime($academicYear['summer_semester_start']) : null;

                                if (!function_exists('formatColloquiumWeekLabel')) {
                                    function formatColloquiumWeekLabel($week, $semester, $winterStart, $summerStart)
                                    {
                                        if ($week === null) return 'Nije uneseno';
                                        $week = (int)$week;
                                        if ($week <= 0) return 'Nije uneseno';
                                        if ($week === 1) return 'Ne održava se';

                                        $semStart = ($semester % 2 !== 0) ? $winterStart : $summerStart;
                                        $label = $week . '. sedmica';
                                        if ($semStart) {
                                            $wStart = strtotime('+' . ($week - 1) . ' weeks', $semStart);
                                            $wEnd = strtotime('+6 days', $wStart);
                                            $label .= ' (' . date('d.m.Y', $wStart) . '-' . date('d.m.Y', $wEnd) . ')';
                                        }
                                        return $label;
                                    }
                                }

                                $stmt = $pdo->query("SELECT c.id, c.name, c.semester, c.colloquium_1_week, c.colloquium_2_week, p.full_name, cp.is_assistant
                                                    FROM course c
                                                    LEFT JOIN course_professor cp ON cp.course_id = c.id
                                                    LEFT JOIN professor p ON p.id = cp.professor_id
                                                    ORDER BY c.name, cp.is_assistant, p.full_name");
                                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                $courses = [];
                                foreach ($rows as $row) {
                                    $cid = (int)$row['id'];
                                    if (!isset($courses[$cid])) {
                                        $courses[$cid] = [
                                            'name' => $row['name'],
                                            'semester' => (int)$row['semester'],
                                            'col1' => $row['colloquium_1_week'],
                                            'col2' => $row['colloquium_2_week'],
                                            'professors' => [],
                                            'assistants' => []
                                        ];
                                    }

                                    if (!empty($row['full_name'])) {
                                        if ((int)$row['is_assistant'] === 1) {
                                            $courses[$cid]['assistants'][] = $row['full_name'];
                                        } else {
                                            $courses[$cid]['professors'][] = $row['full_name'];
                                        }
                                    }
                                }

                                if (empty($courses)) {
                                    echo "<tr><td colspan='5'>Nema predmeta za prikaz.</td></tr>";
                                } else {
                                    foreach ($courses as $course) {
                                        $profList = !empty($course['professors']) ? implode(', ', array_unique($course['professors'])) : '—';
                                        $asstList = !empty($course['assistants']) ? implode(', ', array_unique($course['assistants'])) : '—';

                                        $col1Label = formatColloquiumWeekLabel($course['col1'], $course['semester'], $winterStart, $summerStart);
                                        $col2Label = formatColloquiumWeekLabel($course['col2'], $course['semester'], $winterStart, $summerStart);

                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($course['name']) . '</td>';
                                        echo '<td>' . htmlspecialchars($profList) . '</td>';
                                        echo '<td>' . htmlspecialchars($asstList) . '</td>';
                                        echo '<td>' . htmlspecialchars($col1Label) . '</td>';
                                        echo '<td>' . htmlspecialchars($col2Label) . '</td>';
                                        echo '</tr>';
                                    }
                                }
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='5'>Greška: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                    <?php
                    break;

                case 'pocetna':
                    echo "<h2>Dobrodošli u Admin Panel</h2>";
                    echo "<p>Odaberite opciju ispod da generišete raspored časova:</p>";

                    echo "<button id='generate-schedule' class='option-button'>Generiši raspored časova</button>";
                    echo "<button id='generate-colloquiums' class='option-button' style='display:none; margin-left: 10px; background-color: #9333ea;'>Generiši kolokvijume</button>";
                    echo "<div id='schedule-status' style='margin-top:20px; display:none'></div>";

                    // Colloquium Section (HIDDEN)
                    echo "
                    <div id='colloquium-section' style='display:none !important; margin-top:30px; border-top: 1px solid #444; padding-top: 20px;'>
                        <h3 style='color: #ecc94b; margin-bottom: 15px;'>Raspored Kolokvijuma</h3>
                        
                        <div style='display:flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;'>
                            <div>
                                <label style='display:block; margin-bottom:5px; color:#aaa;'>Tip Kolokvijuma:</label>
                                <select id='coll-type-select' class='form-control' style='width: 200px; background: #333; color: white; border: 1px solid #555; padding: 8px; border-radius: 4px;'>
                                    <option value='COLLOQUIUM_1'>Kolokvijum 1</option>
                                    <option value='COLLOQUIUM_2'>Kolokvijum 2</option>
                                </select>
                            </div>
                            <div>
                                <label style='display:block; margin-bottom:5px; color:#aaa;'>Semestar:</label>
                                <select id='coll-sem-select' class='form-control' style='width: 200px; background: #333; color: white; border: 1px solid #555; padding: 8px; border-radius: 4px;'>
                                    <option value='1'>Semestar 1</option>
                                    <option value='2'>Semestar 2</option>
                                    <option value='3'>Semestar 3</option>
                                    <option value='4'>Semestar 4</option>
                                    <option value='5'>Semestar 5</option>
                                    <option value='6'>Semestar 6</option>
                                </select>
                            </div>
                        </div>
                        
                        <div id='colloquium-container'></div>
                    </div>
                    ";

                    echo "<div id='schedule-container' style='margin-top:20px; display:none'></div>";


                    ?>

                    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
                    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>


                    <script>
                        const days = ['Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak'];

                        // Shared renderer: builds the full interactive schedule UI from `getschedule` response
                        function renderScheduleData(data) {
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');
                            const colSection = document.getElementById('colloquium-section');

                            // Store exams for filtering
                            window.allExams = data.exams || [];

                            // Check for Colloquiums
                            const hasColloquiums = window.allExams.some(e =>
                                e.type === 'COLLOQUIUM_1' || e.type === 'COLLOQUIUM_2'
                            );

                            // HIDDEN: Colloquium section display logic disabled
                            /*
                            if (colSection) {
                                if (hasColloquiums) {
                                    colSection.style.display = 'block';
                                    if (typeof renderColloquiums === 'function') renderColloquiums();
                                } else {
                                    colSection.style.display = 'none';
                                }
                            }
                            */

                            // clear previous
                            container.innerHTML = '';

                            const schedules = data.schedules || {};
                            const scheduleIds = data.schedule_ids || [];

                            if (scheduleIds.length === 0) {
                                statusDiv.innerHTML = '<p>Nema rasporeda u bazi.</p>';
                                return;
                            }

                            // Collect all events
                            const allEvents = [];
                            scheduleIds.forEach(sid => {
                                Object.keys(schedules[sid] || {}).forEach(sem => {
                                    (schedules[sid][sem] || []).forEach(ev => allEvents.push(ev));
                                });
                            });

                            const timeSlots = Array.from(new Set(allEvents.map(e => e.start + '-' + e.end))).sort();

                            // State
                            let currentWinterIndex = 0;
                            let currentSummerIndex = 0;
                            // initialise lock state from server-exposed global if available
                            let isScheduleLocked = (window.adminData && window.adminData.schedule_locked) ? true : false;
                            const allArrowButtons = [];

                            // createControlGroup copied from previous inline code (keeps same behaviour)
                            function createControlGroup(title, isWinter) {
                                const group = document.createElement('div');
                                group.style.textAlign = 'center';
                                group.style.margin = '10px';

                                const label = document.createElement('h3');
                                label.textContent = title;
                                label.style.marginBottom = '10px';
                                label.style.color = '#e5e7eb';

                                const controls = document.createElement('div');
                                controls.style.display = 'flex';
                                controls.style.alignItems = 'center';
                                controls.style.gap = '15px';
                                controls.style.justifyContent = 'center';

                                const leftBtn = document.createElement('button');
                                leftBtn.innerHTML = '◀';
                                leftBtn.className = 'nav-arrow';
                                leftBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                allArrowButtons.push(leftBtn);

                                const info = document.createElement('span');
                                info.innerHTML = 'Verzija 1';
                                info.style.fontWeight = 'bold';

                                const rightBtn = document.createElement('button');
                                rightBtn.innerHTML = '▶';
                                rightBtn.className = 'nav-arrow';
                                rightBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                allArrowButtons.push(rightBtn);

                                const updateState = () => {
                                    const idx = isWinter ? currentWinterIndex : currentSummerIndex;
                                    info.innerHTML = 'Verzija ' + (idx + 1);

                                    leftBtn.disabled = idx === 0 || isScheduleLocked;
                                    leftBtn.style.opacity = (idx === 0 || isScheduleLocked) ? '0.5' : '1';

                                    rightBtn.disabled = idx === scheduleIds.length - 1 || isScheduleLocked;
                                    rightBtn.style.opacity = (idx === scheduleIds.length - 1 || isScheduleLocked) ? '0.5' : '1';

                                    const sems = isWinter ? [1, 3, 5] : [2, 4, 6];
                                    sems.forEach(s => updateSemesterTable(s));
                                };

                                leftBtn.addEventListener('click', () => {
                                    if (isScheduleLocked) return;
                                    if (isWinter) {
                                        if (currentWinterIndex > 0) currentWinterIndex--;
                                    } else {
                                        if (currentSummerIndex > 0) currentSummerIndex--;
                                    }
                                    updateState();
                                });

                                rightBtn.addEventListener('click', () => {
                                    if (isScheduleLocked) return;
                                    if (isWinter) {
                                        if (currentWinterIndex < scheduleIds.length - 1) currentWinterIndex++;
                                    } else {
                                        if (currentSummerIndex < scheduleIds.length - 1) currentSummerIndex++;
                                    }
                                    updateState();
                                });

                                setTimeout(updateState, 0);

                                controls.appendChild(leftBtn);
                                controls.appendChild(info);
                                controls.appendChild(rightBtn);

                                group.appendChild(label);
                                group.appendChild(controls);
                                return group;
                            }

                            // enableTdSwap and buildTableForSemester functions (copied behaviour)
                            function enableTdSwap(tableEl) {
                                const rows = tableEl.querySelectorAll('tbody tr');
                                rows.forEach((tr) => {
                                    new Sortable(tr, {
                                        group: {name: 'cells', pull: true, put: true},
                                        animation: 150,
                                        draggable: 'td',
                                        filter: '.no-drag',
                                        preventOnFilter: true,
                                        swap: true,
                                        swapClass: 'td-swap-hl',
                                        fallbackOnBody: true,
                                        swapThreshold: 0.65,
                                        invertSwap: true
                                    });
                                });
                            }

                            function formatEventLabel(ev) {
                                const typeMap = {
                                    LECTURE: 'Predavanje',
                                    EXERCISE: 'Vježbe',
                                    LAB: 'Lab'
                                };
                                const typeLabel = typeMap[ev.type] || ev.type || '';
                                const useAssistants = ev.type === 'EXERCISE' || ev.type === 'LAB';
                                const nameList = useAssistants ? (ev.assistants || '') : (ev.professors || '');
                                const fallbackNameList = (!nameList && ev.professors) ? ev.professors : nameList;
                                const profLabel = fallbackNameList ? (' - ' + fallbackNameList) : '';
                                const locationLabel = ev.is_online ? 'ONLINE' : (ev.room || '');
                                const locationSuffix = locationLabel ? (' (' + locationLabel + ')') : '';
                                const typeSuffix = typeLabel ? (' [' + typeLabel + ']') : '';
                                return ev.course + profLabel + typeSuffix + locationSuffix;
                            }

                            function buildTableForSemester(sem, events, scheduleIdx, totalSchedules) {
                                const wrapper = document.createElement('div');
                                wrapper.className = 'semester-wrapper';
                                wrapper.id = 'semester-wrapper-' + sem;
                                wrapper.style.marginBottom = '40px';
                                wrapper.style.border = '1px solid #444';
                                wrapper.style.borderRadius = '12px';
                                wrapper.style.padding = '20px';
                                wrapper.style.background = 'transparent';

                                const header = document.createElement('div');
                                header.style.textAlign = 'center';
                                header.style.marginBottom = '15px';

                                const h3 = document.createElement('h3');
                                const semType = (sem % 2 === 1) ? 'Zimski semestar' : 'Ljetnji semestar';
                                h3.style.margin = '0';
                                h3.innerHTML = sem + '. semestar – ' + semType + '<br><small style="color: #666; font-weight: normal;">(Prikazana verzija: ' + (scheduleIdx + 1) + ')</small>';
                                header.appendChild(h3);
                                wrapper.appendChild(header);

                                if (!events || events.length === 0) {
                                    const noData = document.createElement('p');
                                    noData.textContent = 'Nema podataka za ovaj raspored.';
                                    noData.style.textAlign = 'center';
                                    noData.style.color = '#999';
                                    wrapper.appendChild(noData);
                                    return wrapper;
                                }

                                const table = document.createElement('table');
                                table.border = '1';
                                table.cellPadding = '5';
                                table.style.width = '100%';
                                table.style.borderCollapse = 'collapse';
                                table.className = 'schedule-table';
                                table.setAttribute('data-semester', sem);

                                const thead = document.createElement('thead');
                                const trHead = document.createElement('tr');
                                const thTime = document.createElement('th');
                                thTime.textContent = 'Vrijeme';
                                trHead.appendChild(thTime);
                                days.forEach(d => {
                                    const th = document.createElement('th');
                                    th.textContent = d;
                                    trHead.appendChild(th);
                                });
                                thead.appendChild(trHead);
                                table.appendChild(thead);

                                const tbody = document.createElement('tbody');
                                timeSlots.forEach(slot => {
                                    const hasAnyEvent = events.some(ev => (ev.start + '-' + ev.end) === slot);
                                    if (!hasAnyEvent) return;

                                    const tr = document.createElement('tr');
                                    const tdTime = document.createElement('td');
                                    tdTime.textContent = slot;
                                    tdTime.classList.add('no-drag');
                                    tr.appendChild(tdTime);

                                    for (let d = 1; d <= 5; d++) {
                                        const td = document.createElement('td');
                                        const cellEvents = events.filter(ev => ev.day === d && (ev.start + '-' + ev.end) === slot);
                                        if (cellEvents.length > 0) {
                                            td.innerHTML = cellEvents.map(ev => formatEventLabel(ev)).join('<br>');
                                        }
                                        tr.appendChild(td);
                                    }

                                    tbody.appendChild(tr);
                                });

                                table.appendChild(tbody);
                                wrapper.appendChild(table);
                                enableTdSwap(table);

                                const pdfBtn = document.createElement('button');
                                pdfBtn.textContent = 'Sačuvaj kao PDF';
                                pdfBtn.className = 'action-button add-button';
                                pdfBtn.style.marginTop = '10px';
                                pdfBtn.addEventListener('click', () => {
                                    saveTableAsPDF(table, sem);
                                });
                                wrapper.appendChild(pdfBtn);

                                return wrapper;
                            }

                            function updateSemesterTable(sem) {
                                const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                                if (!oldWrapper) return;

                                const isWinter = (sem % 2 !== 0);
                                const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;
                                const schedId = scheduleIds[schedIdx];
                                const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                                const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                                oldWrapper.replaceWith(newWrapper);
                            }

                            // MASTER CONTROLS
                            const controlsDiv = document.createElement('div');
                            controlsDiv.style.display = 'flex';
                            controlsDiv.style.justifyContent = 'space-around';
                            controlsDiv.style.flexWrap = 'wrap';
                            controlsDiv.style.marginBottom = '30px';
                            controlsDiv.style.padding = '20px';
                            controlsDiv.style.background = '#2d2d2d';
                            controlsDiv.style.borderRadius = '10px';
                            controlsDiv.style.border = '1px solid #444';
                            container.appendChild(controlsDiv);

                            controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                            // Lock button
                            const lockBtnContainer = document.createElement('div');
                            lockBtnContainer.style.display = 'flex';
                            lockBtnContainer.style.alignItems = 'center';
                            lockBtnContainer.style.justifyContent = 'center';
                            lockBtnContainer.style.margin = '10px';
                            const lockBtn = document.createElement('button');
                            // set initial label/style based on current lock state
                            if (isScheduleLocked) {
                                lockBtn.textContent = '🔒 Otključaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #ef4444; background: #ef4444; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                            } else {
                                lockBtn.textContent = '🔓 Zaključaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                            }
                            lockBtnContainer.appendChild(lockBtn);
                            // ensure arrow buttons reflect initial lock state
                            setTimeout(() => {
                                allArrowButtons.forEach(btn => {
                                    if (isScheduleLocked) {
                                        btn.disabled = true;
                                        btn.style.opacity = '0.5';
                                        btn.style.cursor = 'not-allowed';
                                    } else {
                                        btn.disabled = false;
                                        btn.style.opacity = '1';
                                        btn.style.cursor = 'pointer';
                                    }
                                });
                            }, 0);

                            // Lock button click handler (calls server API)
                            lockBtn.addEventListener('click', async () => {
                                // Determine winter and summer schedule IDs based on what's available
                                let winterScheduleId, summerScheduleId;
                                if (scheduleIds.length >= 2) {
                                    winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                    summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                                } else if (scheduleIds.length === 1) {
                                    winterScheduleId = scheduleIds[0];
                                    summerScheduleId = scheduleIds[0];
                                } else {
                                    alert('Greška: Nema dostupnih rasporeda za zaključavanje.');
                                    return;
                                }

                                isScheduleLocked = !isScheduleLocked;

                                try {
                                    const payload = {
                                        action: 'toggle_lock',
                                        is_locked: isScheduleLocked,
                                        winter_schedule_id: winterScheduleId,
                                        summer_schedule_id: summerScheduleId
                                    };

                                    const response = await fetch('../../src/api/schedule_lock.php', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify(payload)
                                    });

                                    const data = await response.json();
                                    if (data && data.success) {
                                        // update UI based on new lock state
                                        if (isScheduleLocked) {
                                            lockBtn.textContent = '🔒 Otkljucaj';
                                            lockBtn.style.background = '#ef4444';
                                            lockBtn.style.borderColor = '#ef4444';
                                        } else {
                                            lockBtn.textContent = '🔓 Zakljucaj';
                                            lockBtn.style.background = '#f59e0b';
                                            lockBtn.style.borderColor = '#f59e0b';
                                        }

                                        allArrowButtons.forEach(btn => {
                                            if (isScheduleLocked) {
                                                btn.disabled = true;
                                                btn.style.opacity = '0.5';
                                                btn.style.cursor = 'not-allowed';
                                            } else {
                                                btn.disabled = false;
                                                btn.style.opacity = '1';
                                                btn.style.cursor = 'pointer';
                                            }
                                        });

                                        // update global flag so subsequent loads see correct state
                                        if (window.adminData) window.adminData.schedule_locked = isScheduleLocked;
                                    } else {
                                        console.error('Failed to save lock state', data && data.message);
                                        // revert
                                        isScheduleLocked = !isScheduleLocked;
                                        alert('Neuspeh pri čuvanju statusa zaključavanja: ' + (data && data.message ? data.message : 'Nepoznata greška'));
                                    }
                                } catch (err) {
                                    console.error('API error', err);
                                    isScheduleLocked = !isScheduleLocked;
                                    alert('Greška pri povezivanju sa serverom.');
                                }
                            });
                            controlsDiv.appendChild(lockBtnContainer);

                            controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                            // Initial render for all semesters
                            [1, 3, 5, 2, 4, 6].forEach(sem => {
                                const schedId = scheduleIds[0];
                                const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                                const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                                container.appendChild(wrapper);
                            });

                            // PDF all
                            const pdfAllBtn = document.createElement('button');
                            pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                            pdfAllBtn.className = 'action-button add-button';
                            pdfAllBtn.style.marginTop = '20px';
                            pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                            container.appendChild(pdfAllBtn);
                        }

                        (function () {
                            try {
                                const genBtn = document.getElementById('generate-schedule');
                                if (!genBtn) return;
                                const lockedDiv = document.createElement('div');
                                lockedDiv.id = 'locked-schedules-list';
                                lockedDiv.style.marginTop = '12px';
                                lockedDiv.style.display = 'block';

                                if (window.adminData && window.adminData.schedule_locked) {
                                    const ids = window.adminData.locked_schedule_ids || [];
                                    if (ids.length > 0) {
                                        // Auto-load the most recent locked schedule so admin sees it immediately
                                        (async () => {
                                            const firstId = ids[0];
                                            const statusDiv = document.getElementById('schedule-status');
                                            const container = document.getElementById('schedule-container');
                                            statusDiv.style.display = 'block';
                                            statusDiv.innerHTML = '<p style="color:#3b82f6;">Učitavanje zaključanog rasporeda ID ' + firstId + '...</p>';
                                            container.style.display = 'none';
                                            container.innerHTML = '';

                                            try {
                                                const res = await fetch('admin_panel.php?action=getschedule&only_locked_id=' + encodeURIComponent(firstId));
                                                if (!res.ok) throw new Error('HTTP ' + res.status);
                                                const data = await res.json();
                                                if (data.error) {
                                                    statusDiv.innerHTML = '<p style="color:#ef4444;">Greška: ' + (data.error || 'Nepoznata greška') + '</p>';
                                                    return;
                                                }
                                                // Render using shared renderer so locked schedule UI matches generated one
                                                statusDiv.innerHTML = '<p style="color:#22c55e;">Prikaz zaključanog rasporeda ID ' + firstId + '</p>';
                                                container.style.display = 'block';
                                                renderScheduleData(data);
                                            } catch (err) {
                                                statusDiv.innerHTML = '<p style="color:#ef4444;">Greška pri učitavanju: ' + err.message + '</p>';
                                            }
                                        })();

                                        // quick-access buttons removed; auto-load of the most recent locked schedule remains
                                    } else {
                                        lockedDiv.innerHTML = '<em>Nema zaključanih rasporeda.</em>';
                                    }
                                }

                                genBtn.parentNode.insertBefore(lockedDiv, genBtn.nextSibling);
                            } catch (e) {
                                console.warn('locked schedules init error', e);
                            }
                        })();
                        document.getElementById('generate-schedule').addEventListener('click', async () => {
                            const button = document.getElementById('generate-schedule');
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');

                            // Disable dugme i prikaži loading stanje
                            button.disabled = true;
                            button.textContent = 'Generiše se...';
                            button.classList.add('loading');

                            statusDiv.style.display = 'block';
                            statusDiv.innerHTML = '<p style="color: #3b82f6;">Generisanje rasporeda u toku, molimo sačekajte...</p>';
                            container.style.display = 'none';
                            container.innerHTML = '';

                            try {
                                // Pozovi Java program za generisanje rasporeda
                                const generateRes = await fetch('admin_panel.php?action=generateschedule');

                                // Provera da li je odgovor validan
                                if (!generateRes.ok) {
                                    throw new Error('HTTP greška: ' + generateRes.status + ' ' + generateRes.statusText);
                                }

                                // Provera da li je odgovor JSON
                                const contentType = generateRes.headers.get('content-type');
                                if (!contentType || !contentType.includes('application/json')) {
                                    const text = await generateRes.text();
                                    throw new Error('Server nije vratio JSON. Odgovor: ' + text.substring(0, 200));
                                }

                                let generateData;
                                try {
                                    const responseText = await generateRes.text();
                                    if (!responseText || responseText.trim() === '') {
                                        throw new Error('Server je vratio prazan odgovor');
                                    }
                                    generateData = JSON.parse(responseText);
                                } catch (jsonError) {
                                    throw new Error('Greška pri parsiranju JSON odgovora: ' + jsonError.message);
                                }

                                if (generateData.status === 'error') {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + generateData.message + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Ako je uspešno generisano, prikaži poruku o uspehu
                                statusDiv.innerHTML = '<p style="color: #22c55e; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid #22c55e;">✓ ' + generateData.message + '</p>';

                                // Sada učitaj i prikaži generisani raspored
                                container.style.display = 'block';

                                const res = await fetch('admin_panel.php?action=getschedule');
                                const data = await res.json();
                                if (data.error) {
                                    statusDiv.innerHTML += '<p style="color: #ef4444; margin-top: 10px;">Greška pri učitavanju rasporeda: ' + data.error + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }
                                // Nova struktura: data.schedules[scheduleId][semester] = events[]
                                // data.schedule_ids = [id1, id2, ...]

                                const schedules = data.schedules || {};
                                const scheduleIds = data.schedule_ids || [];

                                if (scheduleIds.length === 0) {
                                    container.innerHTML = '<p>Nema rasporeda u bazi.</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Skupi sve događaje za vremenske slotove
                                const allEvents = [];
                                scheduleIds.forEach(sid => {
                                    Object.keys(schedules[sid] || {}).forEach(sem => {
                                        (schedules[sid][sem] || []).forEach(ev => allEvents.push(ev));
                                    });
                                });

                                const timeSlots = Array.from(
                                    new Set(allEvents.map(e => e.start + '-' + e.end))
                                ).sort();

                                // State: zimski i ljetnji indeksi
                                let currentWinterIndex = 0;
                                let currentSummerIndex = 0;

                                // --- MASTER CONTROLS (ZIMSKI / LJETNJI) ---
                                const controlsDiv = document.createElement('div');
                                controlsDiv.style.display = 'flex';
                                controlsDiv.style.justifyContent = 'space-around';
                                controlsDiv.style.flexWrap = 'wrap';
                                controlsDiv.style.marginBottom = '30px';
                                controlsDiv.style.padding = '20px';
                                controlsDiv.style.background = '#2d2d2d';
                                controlsDiv.style.borderRadius = '10px';
                                controlsDiv.style.border = '1px solid #444';

                                container.appendChild(controlsDiv);

                                // Lock state management
                                let isScheduleLocked = false; //zakljucavanje rasporeda
                                const allArrowButtons = []; //zakljucavanje rasporeda

                                function createControlGroup(title, isWinter) {
                                    const group = document.createElement('div');
                                    group.style.textAlign = 'center';
                                    group.style.margin = '10px';

                                    const label = document.createElement('h3');
                                    label.textContent = title;
                                    label.style.marginBottom = '10px';
                                    label.style.color = '#e5e7eb';

                                    const controls = document.createElement('div');
                                    controls.style.display = 'flex';
                                    controls.style.alignItems = 'center';
                                    controls.style.gap = '15px';
                                    controls.style.justifyContent = 'center';

                                    const leftBtn = document.createElement('button');
                                    leftBtn.innerHTML = '◀';
                                    leftBtn.className = 'nav-arrow';
                                    leftBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(leftBtn); //zakljucavanje rasporeda

                                    const info = document.createElement('span');
                                    info.innerHTML = 'Verzija 1';
                                    info.style.fontWeight = 'bold';

                                    const rightBtn = document.createElement('button');
                                    rightBtn.innerHTML = '▶';
                                    rightBtn.className = 'nav-arrow';
                                    rightBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(rightBtn); //zakljucavanje rasporeda


                                    const updateState = () => {
                                        const idx = isWinter ? currentWinterIndex : currentSummerIndex;
                                        info.innerHTML = 'Verzija ' + (idx + 1);

                                        //zakljucavanje rasporeda
                                        leftBtn.disabled = idx === 0 || isScheduleLocked;
                                        leftBtn.style.opacity = (idx === 0 || isScheduleLocked) ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1 || isScheduleLocked;
                                        rightBtn.style.opacity = (idx === scheduleIds.length - 1 || isScheduleLocked) ? '0.5' : '1';


                                        leftBtn.disabled = idx === 0;
                                        leftBtn.style.opacity = idx === 0 ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1;
                                        rightBtn.style.opacity = idx === scheduleIds.length - 1 ? '0.5' : '1';

                                        // Update relevant tables
                                        const sems = isWinter ? [1, 3, 5] : [2, 4, 6];
                                        sems.forEach(s => updateSemesterTable(s));
                                    };

                                    leftBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex > 0) currentWinterIndex--;
                                        } else {
                                            if (currentSummerIndex > 0) currentSummerIndex--;
                                        }
                                        updateState();
                                    });

                                    rightBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex < scheduleIds.length - 1) currentWinterIndex++;
                                        } else {
                                            if (currentSummerIndex < scheduleIds.length - 1) currentSummerIndex++;
                                        }
                                        updateState();
                                    });

                                    // Initial call
                                    setTimeout(updateState, 0);

                                    controls.appendChild(leftBtn);
                                    controls.appendChild(info);
                                    controls.appendChild(rightBtn);

                                    group.appendChild(label);
                                    group.appendChild(controls);
                                    return group;
                                }

                                controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                                //Zakljucavanje rasporeda - START
                                // Lock button
                                const lockBtnContainer = document.createElement('div');
                                lockBtnContainer.style.display = 'flex';
                                lockBtnContainer.style.alignItems = 'center';
                                lockBtnContainer.style.justifyContent = 'center';
                                lockBtnContainer.style.margin = '10px';

                                const lockBtn = document.createElement('button');
                                lockBtn.textContent = '🔓 Zakljucaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';

                                lockBtn.addEventListener('click', async () => {
                                    // Determine winter and summer schedule IDs based on what's available
                                    // If we have multiple schedules, use different ones for winter/summer
                                    // If only 1 schedule, use it for both
                                    let winterScheduleId, summerScheduleId;

                                    if (scheduleIds.length >= 2) {
                                        // Multiple schedules: use first for winter, second for summer
                                        winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                        summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                                    } else if (scheduleIds.length === 1) {
                                        // Only 1 schedule: use it for both
                                        winterScheduleId = scheduleIds[0];
                                        summerScheduleId = scheduleIds[0];
                                    } else {
                                        // No schedules at all
                                        console.error('✗ No schedule IDs found');
                                        alert('Greška: Nema dostupnih rasporeda.');
                                        return;
                                    }

                                    // console.log('=== LOCK TOGGLE ===');
                                    // console.log('All Available Schedule IDs:', scheduleIds);
                                    // console.log('Winter Index:', currentWinterIndex);
                                    // console.log('Summer Index:', currentSummerIndex);
                                    // console.log('Winter Schedule ID to lock:', winterScheduleId);
                                    // console.log('Summer Schedule ID to lock:', summerScheduleId);

                                    isScheduleLocked = !isScheduleLocked;

                                    // console.log('Winter scheduleId: ',winterScheduleId);
                                    // console.log('Summer schedule Id: ',summerScheduleId);
                                    // console.log('Is Locked:', isScheduleLocked);
                                    // console.log('==================');


                                    // Make AJAX call to API
                                    try {
                                        const payload = {
                                            action: 'toggle_lock',
                                            is_locked: isScheduleLocked,
                                            winter_schedule_id: winterScheduleId,
                                            summer_schedule_id: summerScheduleId
                                        };

                                        // console.log('Sending payload:', JSON.stringify(payload, null, 2));

                                        const response = await fetch('../../src/api/schedule_lock.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                            },
                                            body: JSON.stringify(payload)
                                        });

                                        const data = await response.json();
                                        console.log('API Response:', data);

                                        if (data.success) {
                                            // console.log('✓ Lock state saved:', data.message);

                                            // Update button appearance AFTER API succeeds
                                            if (isScheduleLocked) {
                                                lockBtn.textContent = '🔒 Otkljucaj';
                                                lockBtn.style.background = '#ef4444';
                                                lockBtn.style.borderColor = '#ef4444';
                                            } else {
                                                lockBtn.textContent = '🔓 Zakljucaj';
                                                lockBtn.style.background = '#f59e0b';
                                                lockBtn.style.borderColor = '#f59e0b';
                                            }

                                            // Update all arrow buttons AFTER API succeeds
                                            allArrowButtons.forEach(btn => {
                                                if (isScheduleLocked) {
                                                    btn.disabled = true;
                                                    btn.style.opacity = '0.5';
                                                    btn.style.cursor = 'not-allowed';
                                                } else {
                                                    btn.disabled = false;
                                                    btn.style.opacity = '1';
                                                    btn.style.cursor = 'pointer';
                                                }
                                            });
                                        } else {
                                            console.error('✗ Failed to save lock state:', data.message);
                                            // Revert the toggle if API call failed
                                            isScheduleLocked = !isScheduleLocked;
                                        }
                                    } catch (error) {
                                        console.error('✗ API call error:', error);
                                        // Revert the toggle if API call failed
                                        isScheduleLocked = !isScheduleLocked;
                                    }
                                });

                                lockBtnContainer.appendChild(lockBtn);
                                controlsDiv.appendChild(lockBtnContainer);

                                //Zakljucavanje rasporeda - END

                                controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                                function enableTdSwap(tableEl) {
                                    const rows = tableEl.querySelectorAll('tbody tr');

                                    rows.forEach((tr) => {
                                        new Sortable(tr, {
                                            group: {name: 'cells', pull: true, put: true},
                                            animation: 150,
                                            draggable: 'td',

                                            filter: '.no-drag',
                                            preventOnFilter: true,

                                            swap: true,
                                            swapClass: 'td-swap-hl',

                                            fallbackOnBody: true,
                                            swapThreshold: 0.65,
                                            invertSwap: true
                                        });
                                    });
                                }


                                function buildTableForSemester(sem, events, scheduleIdx, totalSchedules) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + sem;
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    // NOVI HEADER BEZ STRELICA (Kontrola je sada na vrhu)
                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    const semType = (sem % 2 === 1) ? 'Zimski semestar' : 'Ljetnji semestar';
                                    h3.style.margin = '0';
                                    h3.innerHTML = sem + '. semestar – ' + semType +
                                        '<br><small style="color: #666; font-weight: normal;">(Prikazana verzija: ' + (scheduleIdx + 1) + ')</small>';

                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    // Tabela
                                    if (!events || events.length === 0) {
                                        const noData = document.createElement('p');
                                        noData.textContent = 'Nema podataka za ovaj raspored.';
                                        noData.style.textAlign = 'center';
                                        noData.style.color = '#999';
                                        wrapper.appendChild(noData);
                                    } else {
                                        const table = document.createElement('table');
                                        table.border = '1';
                                        table.cellPadding = '5';
                                        table.style.width = '100%';
                                        table.style.borderCollapse = 'collapse';
                                        table.className = 'schedule-table';
                                        table.setAttribute('data-semester', sem);

                                        const thead = document.createElement('thead');
                                        const trHead = document.createElement('tr');
                                        const thTime = document.createElement('th');
                                        thTime.textContent = 'Vrijeme';
                                        trHead.appendChild(thTime);
                                        days.forEach(d => {
                                            const th = document.createElement('th');
                                            th.textContent = d;
                                            trHead.appendChild(th);
                                        });
                                        thead.appendChild(trHead);
                                        table.appendChild(thead);

                                        const tbody = document.createElement('tbody');

                                        timeSlots.forEach(slot => {
                                            const hasAnyEvent = events.some(ev =>
                                                (ev.start + '-' + ev.end) === slot
                                            );
                                            if (!hasAnyEvent) return;

                                            const tr = document.createElement('tr');
                                            const tdTime = document.createElement('td');
                                            tdTime.textContent = slot;
                                            tdTime.classList.add('no-drag');
                                            tr.appendChild(tdTime);

                                            for (let d = 1; d <= 5; d++) {
                                                const td = document.createElement('td');
                                                const cellEvents = events.filter(ev =>
                                                    ev.day === d && (ev.start + '-' + ev.end) === slot
                                                );
                                                if (cellEvents.length > 0) {
                                                    td.innerHTML = cellEvents
                                                        .map(ev => ev.course + ' (' + ev.room + ')')
                                                        .join('<br>');
                                                }
                                                tr.appendChild(td);
                                            }

                                            tbody.appendChild(tr);
                                        });

                                        table.appendChild(tbody);
                                        wrapper.appendChild(table);
                                        enableTdSwap(table);

                                        // PDF dugme
                                        const pdfBtn = document.createElement('button');
                                        pdfBtn.textContent = 'Sačuvaj kao PDF';
                                        pdfBtn.className = 'action-button add-button';
                                        pdfBtn.style.marginTop = '10px';
                                        pdfBtn.addEventListener('click', () => {
                                            saveTableAsPDF(table, sem);
                                        });
                                        wrapper.appendChild(pdfBtn);
                                    }

                                    return wrapper;
                                }

                                function updateSemesterTable(sem) {
                                    const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                                    if (!oldWrapper) return;

                                    // Determine if winter or summer
                                    const isWinter = (sem % 2 !== 0);
                                    const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;

                                    const schedId = scheduleIds[schedIdx];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                                    const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                                    oldWrapper.replaceWith(newWrapper);
                                }

                                // Helper function for Exams (List View)
                                function buildSimpleScheduleTable(titleText, events, idSuffix) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + idSuffix;
                                    wrapper.style.marginTop = '60px';
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    h3.style.margin = '0';
                                    h3.innerHTML = titleText;
                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    if (!events || events.length === 0) {
                                        const p = document.createElement('p');
                                        p.textContent = 'Nema zakazanih kolokvijuma.';
                                        p.style.textAlign = 'center';
                                        p.style.color = '#999';
                                        wrapper.appendChild(p);
                                        return wrapper;
                                    }

                                    const table = document.createElement('table');
                                    table.border = '1';
                                    table.cellPadding = '5';
                                    table.style.width = '100%';
                                    table.style.borderCollapse = 'collapse';
                                    table.className = 'schedule-table';

                                    const thead = document.createElement('thead');
                                    const trHead = document.createElement('tr');
                                    ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'].forEach(text => {
                                        const th = document.createElement('th');
                                        th.textContent = text;
                                        // Reuse existing styles logic via class, but enforce headers
                                        // trHead.appendChild(th);
                                    });

                                    // Or better, stick to valid DOM
                                    const headers = ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'];
                                    headers.forEach(h => {
                                        const th = document.createElement('th');
                                        th.textContent = h;
                                        trHead.appendChild(th);
                                    });

                                    thead.appendChild(trHead);
                                    table.appendChild(thead);

                                    const tbody = document.createElement('tbody');

                                    // Sort events by date and time
                                    events.sort((a, b) => {
                                        const dateA = a.date || '';
                                        const dateB = b.date || '';
                                        if (dateA !== dateB) return dateA < dateB ? -1 : 1;
                                        return a.start.localeCompare(b.start);
                                    });

                                    events.forEach(ev => {
                                        const tr = document.createElement('tr');

                                        // Date
                                        const tdDate = document.createElement('td');
                                        tdDate.textContent = ev.date || '-';
                                        tr.appendChild(tdDate);

                                        // Day
                                        const tdDay = document.createElement('td');
                                        tdDay.textContent = ev.day;
                                        tr.appendChild(tdDay);

                                        // Time
                                        const tdTime = document.createElement('td');
                                        tdTime.textContent = ev.start + ' - ' + ev.end;
                                        tr.appendChild(tdTime);

                                        // Course
                                        const tdCourse = document.createElement('td');
                                        tdCourse.textContent = ev.course;
                                        tr.appendChild(tdCourse);

                                        // Room
                                        const tdRoom = document.createElement('td');
                                        tdRoom.textContent = ev.room;
                                        tr.appendChild(tdRoom);

                                        tbody.appendChild(tr);
                                    });

                                    table.appendChild(tbody);
                                    wrapper.appendChild(table);

                                    // Print button for exams
                                    const pdfBtn = document.createElement('button');
                                    pdfBtn.textContent = 'Sačuvaj kolokvijume kao PDF';
                                    pdfBtn.className = 'action-button add-button';
                                    pdfBtn.style.marginTop = '10px';
                                    pdfBtn.addEventListener('click', () => {
                                        saveTableAsPDF(table, 'kolokvijumi');
                                    });
                                    wrapper.appendChild(pdfBtn);

                                    return wrapper;
                                }

                                // Inicijalni prikaz - svi semestri sa prvim rasporedom
                                [1, 3, 5, 2, 4, 6].forEach(sem => {
                                    const schedId = scheduleIds[0];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                                    const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                                    container.appendChild(wrapper);
                                });

                                // Dugme za PDF svih
                                const pdfAllBtn = document.createElement('button');
                                pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                                pdfAllBtn.className = 'action-button add-button';
                                pdfAllBtn.style.marginTop = '20px';
                                pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                                container.appendChild(pdfAllBtn);

                                // --- EXAM TABLE (Raspored Kolokvijuma) ---
                                if (data.exams && data.exams.length > 0) {
                                    const exWrapper = buildSimpleScheduleTable('Raspored kolokvijuma (Ispitni rokovi)', data.exams, 'exams');
                                    container.appendChild(exWrapper);
                                }

                                // Vrati dugme u normalno stanje
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');

                            } catch (e) {
                                statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška pri generisanju rasporeda: ' + e.message + '</p>';
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');
                            }
                        });
                    </script>
                    <script>
                        function saveFullScheduleAsPDF() {
                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');

                            let y = 40;

                            doc.setFontSize(18);
                            doc.text('Kompletan raspored časova', 40, y);
                            y += 30;

                            const tables = document.querySelectorAll('.schedule-table');

                            tables.forEach((table, index) => {
                                if (index > 0) {
                                    doc.addPage();
                                    y = 40;

                                }

                                tbody.appendChild(tr);
                            });

                            table.appendChild(tbody);
                            wrapper.appendChild(table);
                            enableTdSwap(table);

                            const pdfBtn = document.createElement('button');
                            pdfBtn.textContent = 'Sačuvaj kao PDF';
                            pdfBtn.className = 'action-button add-button';
                            pdfBtn.style.marginTop = '10px';
                            pdfBtn.addEventListener('click', () => {
                                saveTableAsPDF(table, sem);
                            });
                            wrapper.appendChild(pdfBtn);

                            return wrapper;
                        }

                        function updateSemesterTable(sem) {
                            const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                            if (!oldWrapper) return;

                            const isWinter = (sem % 2 !== 0);
                            const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;
                            const schedId = scheduleIds[schedIdx];
                            const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                            const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                            oldWrapper.replaceWith(newWrapper);
                        }

                        // MASTER CONTROLS
                        const controlsDiv = document.createElement('div');
                        controlsDiv.style.display = 'flex';
                        controlsDiv.style.justifyContent = 'space-around';
                        controlsDiv.style.flexWrap = 'wrap';
                        controlsDiv.style.marginBottom = '30px';
                        controlsDiv.style.padding = '20px';
                        controlsDiv.style.background = '#2d2d2d';
                        controlsDiv.style.borderRadius = '10px';
                        controlsDiv.style.border = '1px solid #444';
                        container.appendChild(controlsDiv);

                        controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                        // Lock button
                        const lockBtnContainer = document.createElement('div');
                        lockBtnContainer.style.display = 'flex';
                        lockBtnContainer.style.alignItems = 'center';
                        lockBtnContainer.style.justifyContent = 'center';
                        lockBtnContainer.style.margin = '10px';
                        const lockBtn = document.createElement('button');
                        // set initial label/style based on current lock state
                        if (isScheduleLocked) {
                            lockBtn.textContent = '🔒 Otključaj';
                            lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #ef4444; background: #ef4444; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                        } else {
                            lockBtn.textContent = '🔓 Zaključaj';
                            lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                        }
                        lockBtnContainer.appendChild(lockBtn);
                        // ensure arrow buttons reflect initial lock state
                        setTimeout(() => {
                            allArrowButtons.forEach(btn => {
                                if (isScheduleLocked) {
                                    btn.disabled = true;
                                    btn.style.opacity = '0.5';
                                    btn.style.cursor = 'not-allowed';
                                } else {
                                    btn.disabled = false;
                                    btn.style.opacity = '1';
                                    btn.style.cursor = 'pointer';
                                }
                            });
                        }, 0);

                        // Lock button click handler (calls server API)
                        lockBtn.addEventListener('click', async () => {
                            // Determine winter and summer schedule IDs based on what's available
                            let winterScheduleId, summerScheduleId;
                            if (scheduleIds.length >= 2) {
                                winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                            } else if (scheduleIds.length === 1) {
                                winterScheduleId = scheduleIds[0];
                                summerScheduleId = scheduleIds[0];
                            } else {
                                alert('Greška: Nema dostupnih rasporeda za zaključavanje.');
                                return;
                            }

                            isScheduleLocked = !isScheduleLocked;

                            try {
                                const payload = {
                                    action: 'toggle_lock',
                                    is_locked: isScheduleLocked,
                                    winter_schedule_id: winterScheduleId,
                                    summer_schedule_id: summerScheduleId
                                };

                                const response = await fetch('../../src/api/schedule_lock.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify(payload)
                                });

                                const data = await response.json();
                                if (data && data.success) {
                                    // update UI based on new lock state
                                    if (isScheduleLocked) {
                                        lockBtn.textContent = '🔒 Otkljucaj';
                                        lockBtn.style.background = '#ef4444';
                                        lockBtn.style.borderColor = '#ef4444';
                                    } else {
                                        lockBtn.textContent = '🔓 Zakljucaj';
                                        lockBtn.style.background = '#f59e0b';
                                        lockBtn.style.borderColor = '#f59e0b';
                                    }

                                    allArrowButtons.forEach(btn => {
                                        if (isScheduleLocked) {
                                            btn.disabled = true;
                                            btn.style.opacity = '0.5';
                                            btn.style.cursor = 'not-allowed';
                                        } else {
                                            btn.disabled = false;
                                            btn.style.opacity = '1';
                                            btn.style.cursor = 'pointer';
                                        }
                                    });

                                    // update global flag so subsequent loads see correct state
                                    if (window.adminData) window.adminData.schedule_locked = isScheduleLocked;
                                } else {
                                    console.error('Failed to save lock state', data && data.message);
                                    // revert
                                    isScheduleLocked = !isScheduleLocked;
                                    alert('Neuspeh pri čuvanju statusa zaključavanja: ' + (data && data.message ? data.message : 'Nepoznata greška'));
                                }
                            } catch (err) {
                                console.error('API error', err);
                                isScheduleLocked = !isScheduleLocked;
                                alert('Greška pri povezivanju sa serverom.');
                            }
                        });
                        controlsDiv.appendChild(lockBtnContainer);

                        controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                        // Initial render for all semesters
                        [1, 3, 5, 2, 4, 6].forEach(sem => {
                            const schedId = scheduleIds[0];
                            const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                            const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                            container.appendChild(wrapper);
                        });

                        // PDF all
                        const pdfAllBtn = document.createElement('button');
                        pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                        pdfAllBtn.className = 'action-button add-button';
                        pdfAllBtn.style.marginTop = '20px';
                        pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                        container.appendChild(pdfAllBtn);
                        }

                        (function () {
                            try {
                                const genBtn = document.getElementById('generate-schedule');
                                if (!genBtn) return;
                                const lockedDiv = document.createElement('div');
                                lockedDiv.id = 'locked-schedules-list';
                                lockedDiv.style.marginTop = '12px';
                                lockedDiv.style.display = 'block';

                                if (window.adminData && window.adminData.schedule_locked) {
                                    const ids = window.adminData.locked_schedule_ids || [];
                                    if (ids.length > 0) {
                                        // Auto-load the most recent locked schedule so admin sees it immediately
                                        (async () => {
                                            const firstId = ids[0];
                                            const statusDiv = document.getElementById('schedule-status');
                                            const container = document.getElementById('schedule-container');
                                            statusDiv.style.display = 'block';
                                            statusDiv.innerHTML = '<p style="color:#3b82f6;">Učitavanje zaključanog rasporeda ID ' + firstId + '...</p>';
                                            container.style.display = 'none';
                                            container.innerHTML = '';

                                            try {
                                                const res = await fetch('admin_panel.php?action=getschedule&only_locked_id=' + encodeURIComponent(firstId));
                                                if (!res.ok) throw new Error('HTTP ' + res.status);
                                                const data = await res.json();
                                                if (data.error) {
                                                    statusDiv.innerHTML = '<p style="color:#ef4444;">Greška: ' + (data.error || 'Nepoznata greška') + '</p>';
                                                    return;
                                                }
                                                // Render using shared renderer so locked schedule UI matches generated one
                                                statusDiv.innerHTML = '<p style="color:#22c55e;">Prikaz zaključanog rasporeda ID ' + firstId + '</p>';
                                                container.style.display = 'block';
                                                renderScheduleData(data);
                                            } catch (err) {
                                                statusDiv.innerHTML = '<p style="color:#ef4444;">Greška pri učitavanju: ' + err.message + '</p>';
                                            }
                                        })();

                                        // quick-access buttons removed; auto-load of the most recent locked schedule remains
                                    } else {
                                        lockedDiv.innerHTML = '<em>Nema zaključanih rasporeda.</em>';
                                    }
                                }

                                genBtn.parentNode.insertBefore(lockedDiv, genBtn.nextSibling);
                            } catch (e) {
                                console.warn('locked schedules init error', e);
                            }
                        })();
                        document.getElementById('generate-schedule').addEventListener('click', async () => {
                            const button = document.getElementById('generate-schedule');
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');

                            // Disable dugme i prikaži loading stanje
                            button.disabled = true;
                            button.textContent = 'Generiše se...';
                            button.classList.add('loading');

                            statusDiv.style.display = 'block';
                            statusDiv.innerHTML = '<p style="color: #3b82f6;">Generisanje rasporeda u toku, molimo sačekajte...</p>';
                            container.style.display = 'none';
                            container.innerHTML = '';

                            try {
                                // Pozovi Java program za generisanje rasporeda
                                const generateRes = await fetch('admin_panel.php?action=generateschedule');

                                // Provera da li je odgovor validan
                                if (!generateRes.ok) {
                                    throw new Error('HTTP greška: ' + generateRes.status + ' ' + generateRes.statusText);
                                }

                                // Provera da li je odgovor JSON
                                const contentType = generateRes.headers.get('content-type');
                                if (!contentType || !contentType.includes('application/json')) {
                                    const text = await generateRes.text();
                                    throw new Error('Server nije vratio JSON. Odgovor: ' + text.substring(0, 200));
                                }

                                let generateData;
                                try {
                                    const responseText = await generateRes.text();
                                    if (!responseText || responseText.trim() === '') {
                                        throw new Error('Server je vratio prazan odgovor');
                                    }
                                    generateData = JSON.parse(responseText);
                                } catch (jsonError) {
                                    throw new Error('Greška pri parsiranju JSON odgovora: ' + jsonError.message);
                                }

                                if (generateData.status === 'error') {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + generateData.message + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Ako je uspešno generisano, prikaži poruku o uspehu
                                statusDiv.innerHTML = '<p style="color: #22c55e; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid #22c55e;">✓ ' + generateData.message + '</p>';

                                // Sada učitaj i prikaži generisani raspored
                                container.style.display = 'block';

                                const res = await fetch('admin_panel.php?action=getschedule');
                                const data = await res.json();
                                if (data.error) {
                                    statusDiv.innerHTML += '<p style="color: #ef4444; margin-top: 10px;">Greška pri učitavanju rasporeda: ' + data.error + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }
                                // Nova struktura: data.schedules[scheduleId][semester] = events[]
                                // data.schedule_ids = [id1, id2, ...]

                                const schedules = data.schedules || {};
                                const scheduleIds = data.schedule_ids || [];

                                if (scheduleIds.length === 0) {
                                    container.innerHTML = '<p>Nema rasporeda u bazi.</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Skupi sve događaje za vremenske slotove
                                const allEvents = [];
                                scheduleIds.forEach(sid => {
                                    Object.keys(schedules[sid] || {}).forEach(sem => {
                                        (schedules[sid][sem] || []).forEach(ev => allEvents.push(ev));
                                    });
                                });

                                const timeSlots = Array.from(
                                    new Set(allEvents.map(e => e.start + '-' + e.end))
                                ).sort();

                                // State: zimski i ljetnji indeksi
                                let currentWinterIndex = 0;
                                let currentSummerIndex = 0;

                                // --- MASTER CONTROLS (ZIMSKI / LJETNJI) ---
                                const controlsDiv = document.createElement('div');
                                controlsDiv.style.display = 'flex';
                                controlsDiv.style.justifyContent = 'space-around';
                                controlsDiv.style.flexWrap = 'wrap';
                                controlsDiv.style.marginBottom = '30px';
                                controlsDiv.style.padding = '20px';
                                controlsDiv.style.background = '#2d2d2d';
                                controlsDiv.style.borderRadius = '10px';
                                controlsDiv.style.border = '1px solid #444';

                                container.appendChild(controlsDiv);

                                // Lock state management
                                let isScheduleLocked = false; //zakljucavanje rasporeda
                                const allArrowButtons = []; //zakljucavanje rasporeda

                                function createControlGroup(title, isWinter) {
                                    const group = document.createElement('div');
                                    group.style.textAlign = 'center';
                                    group.style.margin = '10px';

                                    const label = document.createElement('h3');
                                    label.textContent = title;
                                    label.style.marginBottom = '10px';
                                    label.style.color = '#e5e7eb';

                                    const controls = document.createElement('div');
                                    controls.style.display = 'flex';
                                    controls.style.alignItems = 'center';
                                    controls.style.gap = '15px';
                                    controls.style.justifyContent = 'center';

                                    const leftBtn = document.createElement('button');
                                    leftBtn.innerHTML = '◀';
                                    leftBtn.className = 'nav-arrow';
                                    leftBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(leftBtn); //zakljucavanje rasporeda

                                    const info = document.createElement('span');
                                    info.innerHTML = 'Verzija 1';
                                    info.style.fontWeight = 'bold';

                                    const rightBtn = document.createElement('button');
                                    rightBtn.innerHTML = '▶';
                                    rightBtn.className = 'nav-arrow';
                                    rightBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(rightBtn); //zakljucavanje rasporeda


                                    const updateState = () => {
                                        const idx = isWinter ? currentWinterIndex : currentSummerIndex;
                                        info.innerHTML = 'Verzija ' + (idx + 1);

                                        //zakljucavanje rasporeda
                                        leftBtn.disabled = idx === 0 || isScheduleLocked;
                                        leftBtn.style.opacity = (idx === 0 || isScheduleLocked) ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1 || isScheduleLocked;
                                        rightBtn.style.opacity = (idx === scheduleIds.length - 1 || isScheduleLocked) ? '0.5' : '1';


                                        leftBtn.disabled = idx === 0;
                                        leftBtn.style.opacity = idx === 0 ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1;
                                        rightBtn.style.opacity = idx === scheduleIds.length - 1 ? '0.5' : '1';

                                        // Update relevant tables
                                        const sems = isWinter ? [1, 3, 5] : [2, 4, 6];
                                        sems.forEach(s => updateSemesterTable(s));
                                    };

                                    leftBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex > 0) currentWinterIndex--;
                                        } else {
                                            if (currentSummerIndex > 0) currentSummerIndex--;
                                        }
                                        updateState();
                                    });

                                    rightBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex < scheduleIds.length - 1) currentWinterIndex++;
                                        } else {
                                            if (currentSummerIndex < scheduleIds.length - 1) currentSummerIndex++;
                                        }
                                        updateState();
                                    });

                                    // Initial call
                                    setTimeout(updateState, 0);

                                    controls.appendChild(leftBtn);
                                    controls.appendChild(info);
                                    controls.appendChild(rightBtn);

                                    group.appendChild(label);
                                    group.appendChild(controls);
                                    return group;
                                }

                                controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                                //Zakljucavanje rasporeda - START
                                // Lock button
                                const lockBtnContainer = document.createElement('div');
                                lockBtnContainer.style.display = 'flex';
                                lockBtnContainer.style.alignItems = 'center';
                                lockBtnContainer.style.justifyContent = 'center';
                                lockBtnContainer.style.margin = '10px';

                                const lockBtn = document.createElement('button');
                                lockBtn.textContent = '🔓 Zakljucaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';

                                lockBtn.addEventListener('click', async () => {
                                    // Determine winter and summer schedule IDs based on what's available
                                    // If we have multiple schedules, use different ones for winter/summer
                                    // If only 1 schedule, use it for both
                                    let winterScheduleId, summerScheduleId;

                                    if (scheduleIds.length >= 2) {
                                        // Multiple schedules: use first for winter, second for summer
                                        winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                        summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                                    } else if (scheduleIds.length === 1) {
                                        // Only 1 schedule: use it for both
                                        winterScheduleId = scheduleIds[0];
                                        summerScheduleId = scheduleIds[0];
                                    } else {
                                        // No schedules at all
                                        console.error('✗ No schedule IDs found');
                                        alert('Greška: Nema dostupnih rasporeda.');
                                        return;
                                    }

                                    // console.log('=== LOCK TOGGLE ===');
                                    // console.log('All Available Schedule IDs:', scheduleIds);
                                    // console.log('Winter Index:', currentWinterIndex);
                                    // console.log('Summer Index:', currentSummerIndex);
                                    // console.log('Winter Schedule ID to lock:', winterScheduleId);
                                    // console.log('Summer Schedule ID to lock:', summerScheduleId);

                                    isScheduleLocked = !isScheduleLocked;

                                    // console.log('Winter scheduleId: ',winterScheduleId);
                                    // console.log('Summer schedule Id: ',summerScheduleId);
                                    // console.log('Is Locked:', isScheduleLocked);
                                    // console.log('==================');


                                    // Make AJAX call to API
                                    try {
                                        const payload = {
                                            action: 'toggle_lock',
                                            is_locked: isScheduleLocked,
                                            winter_schedule_id: winterScheduleId,
                                            summer_schedule_id: summerScheduleId
                                        };

                                        // console.log('Sending payload:', JSON.stringify(payload, null, 2));

                                        const response = await fetch('../../src/api/schedule_lock.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                            },
                                            body: JSON.stringify(payload)
                                        });

                                        const data = await response.json();
                                        console.log('API Response:', data);

                                        if (data.success) {
                                            // console.log('✓ Lock state saved:', data.message);

                                            // Update button appearance AFTER API succeeds
                                            if (isScheduleLocked) {
                                                lockBtn.textContent = '🔒 Otkljucaj';
                                                lockBtn.style.background = '#ef4444';
                                                lockBtn.style.borderColor = '#ef4444';
                                            } else {
                                                lockBtn.textContent = '🔓 Zakljucaj';
                                                lockBtn.style.background = '#f59e0b';
                                                lockBtn.style.borderColor = '#f59e0b';
                                            }

                                            // Update all arrow buttons AFTER API succeeds
                                            allArrowButtons.forEach(btn => {
                                                if (isScheduleLocked) {
                                                    btn.disabled = true;
                                                    btn.style.opacity = '0.5';
                                                    btn.style.cursor = 'not-allowed';
                                                } else {
                                                    btn.disabled = false;
                                                    btn.style.opacity = '1';
                                                    btn.style.cursor = 'pointer';
                                                }
                                            });
                                        } else {
                                            console.error('✗ Failed to save lock state:', data.message);
                                            // Revert the toggle if API call failed
                                            isScheduleLocked = !isScheduleLocked;
                                        }
                                    } catch (error) {
                                        console.error('✗ API call error:', error);
                                        // Revert the toggle if API call failed
                                        isScheduleLocked = !isScheduleLocked;
                                    }
                                });

                                lockBtnContainer.appendChild(lockBtn);
                                controlsDiv.appendChild(lockBtnContainer);

                                //Zakljucavanje rasporeda - END

                                controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                                function enableTdSwap(tableEl) {
                                    const rows = tableEl.querySelectorAll('tbody tr');

                                    rows.forEach((tr) => {
                                        new Sortable(tr, {
                                            group: {name: 'cells', pull: true, put: true},
                                            animation: 150,
                                            draggable: 'td',

                                            filter: '.no-drag',
                                            preventOnFilter: true,

                                            swap: true,
                                            swapClass: 'td-swap-hl',

                                            fallbackOnBody: true,
                                            swapThreshold: 0.65,
                                            invertSwap: true
                                        });
                                    });
                                }


                                function buildTableForSemester(sem, events, scheduleIdx, totalSchedules) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + sem;
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    // NOVI HEADER BEZ STRELICA (Kontrola je sada na vrhu)
                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    const semType = (sem % 2 === 1) ? 'Zimski semestar' : 'Ljetnji semestar';
                                    h3.style.margin = '0';
                                    h3.innerHTML = sem + '. semestar – ' + semType +
                                        '<br><small style="color: #666; font-weight: normal;">(Prikazana verzija: ' + (scheduleIdx + 1) + ')</small>';

                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    // Tabela
                                    if (!events || events.length === 0) {
                                        const noData = document.createElement('p');
                                        noData.textContent = 'Nema podataka za ovaj raspored.';
                                        noData.style.textAlign = 'center';
                                        noData.style.color = '#999';
                                        wrapper.appendChild(noData);
                                    } else {
                                        const table = document.createElement('table');
                                        table.border = '1';
                                        table.cellPadding = '5';
                                        table.style.width = '100%';
                                        table.style.borderCollapse = 'collapse';
                                        table.className = 'schedule-table';
                                        table.setAttribute('data-semester', sem);

                                        const thead = document.createElement('thead');
                                        const trHead = document.createElement('tr');
                                        const thTime = document.createElement('th');
                                        thTime.textContent = 'Vrijeme';
                                        trHead.appendChild(thTime);
                                        days.forEach(d => {
                                            const th = document.createElement('th');
                                            th.textContent = d;
                                            trHead.appendChild(th);
                                        });
                                        thead.appendChild(trHead);
                                        table.appendChild(thead);

                                        const tbody = document.createElement('tbody');

                                        timeSlots.forEach(slot => {
                                            const hasAnyEvent = events.some(ev =>
                                                (ev.start + '-' + ev.end) === slot
                                            );
                                            if (!hasAnyEvent) return;

                                            const tr = document.createElement('tr');
                                            const tdTime = document.createElement('td');
                                            tdTime.textContent = slot;
                                            tdTime.classList.add('no-drag');
                                            tr.appendChild(tdTime);

                                            for (let d = 1; d <= 5; d++) {
                                                const td = document.createElement('td');
                                                const cellEvents = events.filter(ev =>
                                                    ev.day === d && (ev.start + '-' + ev.end) === slot
                                                );
                                                if (cellEvents.length > 0) {
                                                    td.innerHTML = cellEvents
                                                        .map(ev => ev.course + ' (' + ev.room + ')')
                                                        .join('<br>');
                                                }
                                                tr.appendChild(td);
                                            }

                                            tbody.appendChild(tr);
                                        });

                                        table.appendChild(tbody);
                                        wrapper.appendChild(table);
                                        enableTdSwap(table);

                                        // PDF dugme
                                        const pdfBtn = document.createElement('button');
                                        pdfBtn.textContent = 'Sačuvaj kao PDF';
                                        pdfBtn.className = 'action-button add-button';
                                        pdfBtn.style.marginTop = '10px';
                                        pdfBtn.addEventListener('click', () => {
                                            saveTableAsPDF(table, sem);
                                        });
                                        wrapper.appendChild(pdfBtn);
                                    }

                                    return wrapper;
                                }

                                function updateSemesterTable(sem) {
                                    const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                                    if (!oldWrapper) return;

                                    // Determine if winter or summer
                                    const isWinter = (sem % 2 !== 0);
                                    const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;

                                    const schedId = scheduleIds[schedIdx];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                                    const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                                    oldWrapper.replaceWith(newWrapper);
                                }

                                // Helper function for Exams (List View)
                                function buildSimpleScheduleTable(titleText, events, idSuffix) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + idSuffix;
                                    wrapper.style.marginTop = '60px';
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    h3.style.margin = '0';
                                    h3.innerHTML = titleText;
                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    if (!events || events.length === 0) {
                                        const p = document.createElement('p');
                                        p.textContent = 'Nema zakazanih kolokvijuma.';
                                        p.style.textAlign = 'center';
                                        p.style.color = '#999';
                                        wrapper.appendChild(p);
                                        return wrapper;
                                    }

                                    const table = document.createElement('table');
                                    table.border = '1';
                                    table.cellPadding = '5';
                                    table.style.width = '100%';
                                    table.style.borderCollapse = 'collapse';
                                    table.className = 'schedule-table';

                                    const thead = document.createElement('thead');
                                    const trHead = document.createElement('tr');
                                    ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'].forEach(text => {
                                        const th = document.createElement('th');
                                        th.textContent = text;
                                        // Reuse existing styles logic via class, but enforce headers
                                        // trHead.appendChild(th);
                                    });

                                    // Or better, stick to valid DOM
                                    const headers = ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'];
                                    headers.forEach(h => {
                                        const th = document.createElement('th');
                                        th.textContent = h;
                                        trHead.appendChild(th);
                                    });

                                    thead.appendChild(trHead);
                                    table.appendChild(thead);

                                    const tbody = document.createElement('tbody');

                                    // Sort events by date and time
                                    events.sort((a, b) => {
                                        const dateA = a.date || '';
                                        const dateB = b.date || '';
                                        if (dateA !== dateB) return dateA < dateB ? -1 : 1;
                                        return a.start.localeCompare(b.start);
                                    });

                                    events.forEach(ev => {
                                        const tr = document.createElement('tr');

                                        // Date
                                        const tdDate = document.createElement('td');
                                        tdDate.textContent = ev.date || '-';
                                        tr.appendChild(tdDate);

                                        // Day
                                        const tdDay = document.createElement('td');
                                        tdDay.textContent = ev.day;
                                        tr.appendChild(tdDay);

                                        // Time
                                        const tdTime = document.createElement('td');
                                        tdTime.textContent = ev.start + ' - ' + ev.end;
                                        tr.appendChild(tdTime);

                                        // Course
                                        const tdCourse = document.createElement('td');
                                        tdCourse.textContent = ev.course;
                                        tr.appendChild(tdCourse);

                                        // Room
                                        const tdRoom = document.createElement('td');
                                        tdRoom.textContent = ev.room;
                                        tr.appendChild(tdRoom);

                                        tbody.appendChild(tr);
                                    });

                                    table.appendChild(tbody);
                                    wrapper.appendChild(table);

                                    // Print button for exams
                                    const pdfBtn = document.createElement('button');
                                    pdfBtn.textContent = 'Sačuvaj kolokvijume kao PDF';
                                    pdfBtn.className = 'action-button add-button';
                                    pdfBtn.style.marginTop = '10px';
                                    pdfBtn.addEventListener('click', () => {
                                        saveTableAsPDF(table, 'kolokvijumi');
                                    });
                                    wrapper.appendChild(pdfBtn);

                                    return wrapper;
                                }

                                // Inicijalni prikaz - svi semestri sa prvim rasporedom
                                [1, 3, 5, 2, 4, 6].forEach(sem => {
                                    const schedId = scheduleIds[0];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                                    const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                                    container.appendChild(wrapper);
                                });

                                // Dugme za PDF svih
                                const pdfAllBtn = document.createElement('button');
                                pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                                pdfAllBtn.className = 'action-button add-button';
                                pdfAllBtn.style.marginTop = '20px';
                                pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                                container.appendChild(pdfAllBtn);

                                // --- EXAM TABLE (Raspored Kolokvijuma) ---
                                if (data.exams && data.exams.length > 0) {
                                    const exWrapper = buildSimpleScheduleTable('Raspored kolokvijuma (Ispitni rokovi)', data.exams, 'exams');
                                    container.appendChild(exWrapper);
                                }

                                // Vrati dugme u normalno stanje
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');

                            } catch (e) {
                                statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška pri generisanju rasporeda: ' + e.message + '</p>';
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');
                            }
                        });
                    </script>
                    <script>
                        function saveFullScheduleAsPDF() {
                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');
                            doc.setFont("DejaVuSans");
                            let y = 40;

                            doc.setFontSize(18);
                            doc.text('Kompletan raspored časova', 40, y);
                            y += 30;

                            const tables = document.querySelectorAll('.schedule-table');

                            tables.forEach((table, index) => {
                                if (index > 0) {
                                    doc.addPage();
                                    y = 40;
                                }

                                // Naslov semestra (uzimamo h3 iznad tabele)
                                const title = table.previousSibling?.textContent || `Semestar ${index + 1}`;
                                doc.setFontSize(14);
                                doc.text(title, 40, y);
                                y += 20;

                                const headers = [];
                                const rows = [];

                                table.querySelectorAll('thead th').forEach(th => {
                                    headers.push(th.innerText);
                                });

                                table.querySelectorAll('tbody tr').forEach(tr => {
                                    const row = [];
                                    tr.querySelectorAll('td').forEach(td => {
                                        row.push(td.innerText);
                                    });
                                    rows.push(row);
                                });

                                doc.autoTable({
                                    head: [headers],
                                    body: rows,
                                    startY: y,
                                    styles: {
                                        font: "DejaVuSans",
                                        fontSize: 9,
                                        cellPadding: 4
                                    },
                                    headStyles: {
                                        font: "DejaVuSans",
                                        fillColor: [15, 23, 42] // tamna (kao tvoj UI)
                                    }
                                    bodyStyles: {
                                        font: "DejaVuSans"
                                    }
                                });
                            });

                            doc.save('kompletan_raspored_casova.pdf');
                        }
                    </script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>


                    <script>
                        function saveTableAsPDF(table, semester) {
                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');

                            // AKTIVIRAJ FONT
                            doc.setFont("DejaVuSans");
                            console.log(doc.getFontList());
                            doc.setFontSize(16);
                            doc.text(`Raspored časova – ${semester}. semestar`, 40, 40);
                            let startY = 70;

                            const rows = [];
                            const headers = [];

                            // headeri
                            table.querySelectorAll('thead th').forEach(th => {
                                headers.push(th.innerText);
                            });

                            // redovi
                            table.querySelectorAll('tbody tr').forEach(tr => {
                                const row = [];
                                tr.querySelectorAll('td').forEach(td => {
                                    row.push(td.innerText);
                                });
                                rows.push(row);
                            });

                            doc.autoTable({
                                head: [headers],
                                body: rows,
                                startY: startY,
                                styles: {
                                    font: "DejaVuSans",     // BITNO
                                    fontSize: 9,
                                    cellPadding: 4
                                },
                                headStyles: {
                                    font: "DejaVuSans",     // BITNO
                                    fillColor: [22, 101, 52]
                                },
                                bodyStyles: {
                                    font: "DejaVuSans"      // BITNO
                                }
                            });

                            doc.save(`raspored_semestar_${semester}.pdf`);
                        }
                    </script>

                    <script>
                        document.getElementById('generate-colloquiums').addEventListener('click', async () => {
                            const button = document.getElementById('generate-colloquiums');
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');

                            // if(!confirm("Da li ste sigurni da želite generisati kolokvijume?")) return;

                            button.disabled = true;
                            const originalText = button.textContent;
                            button.textContent = 'Generiše se...';

                            statusDiv.style.display = 'block';
                            statusDiv.innerHTML = '<p style="color: #9333ea;">Generisanje kolokvijuma u toku, molimo sačekajte...</p>';

                            try {
                                const res = await fetch('admin_panel.php?action=generatecolloquiums');
                                if (!res.ok) throw new Error('HTTP error ' + res.status);

                                const text = await res.text();
                                let data;
                                try {
                                    data = JSON.parse(text);
                                } catch (e) {
                                    throw new Error('Invalid JSON: ' + text.substring(0, 100));
                                }

                                if (data.status === 'error') {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + data.message + '</p>';
                                } else {
                                    statusDiv.innerHTML = '<p style="color: #22c55e; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid #22c55e;">✓ ' + data.message + '</p>';

                                    // Refresh schedule
                                    const schedRes = await fetch('admin_panel.php?action=getschedule');
                                    const schedData = await schedRes.json();
                                    if (schedData.error) {
                                        statusDiv.innerHTML += '<p style="color: #ef4444;">Greška pri učitavanju rasporeda: ' + schedData.error + '</p>';
                                    } else {
                                        container.style.display = 'block';
                                        if (typeof renderScheduleData === 'function') {
                                            renderScheduleData(schedData);
                                        } else {
                                            console.error('renderScheduleData function not found');
                                        }
                                    }
                                }
                            } catch (e) {
                                statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + e.message + '</p>';
                                console.error(e);
                            } finally {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                        });

                        // COLLOQUIUM RENDERING LOGIC
                        function renderColloquiums() {
                            const container = document.getElementById('colloquium-container');
                            const typeSelect = document.getElementById('coll-type-select');
                            const semSelect = document.getElementById('coll-sem-select');

                            if (!container || !window.allExams || !typeSelect || !semSelect) return;

                            const type = typeSelect.value;
                            const sem = parseInt(semSelect.value);

                            // Filter events
                            const filtered = window.allExams.filter(e => e.type === type && e.semester === sem);

                            container.innerHTML = '';

                            if (filtered.length === 0) {
                                container.innerHTML = '<p style="color:#aaa; text-align:center; padding:20px;">Nema pronađenih kolokvijuma za odabrane kriterijume.</p>';
                                return;
                            }

                            // Group by Week
                            const getMonday = (d) => {
                                d = new Date(d);
                                const day = d.getDay(), diff = d.getDate() - day + (day == 0 ? -6 : 1);
                                const m = new Date(d.setDate(diff));
                                return m.toISOString().slice(0, 10);
                            };

                            const groups = {};
                            filtered.forEach(ev => {
                                const mon = getMonday(ev.date);
                                if (!groups[mon]) groups[mon] = [];
                                groups[mon].push(ev);
                            });

                            const sortedWeeks = Object.keys(groups).sort();

                            sortedWeeks.forEach((weekStart, idx) => {
                                const events = groups[weekStart];
                                const weekDiv = document.createElement('div');
                                weekDiv.className = 'semester-wrapper';
                                weekDiv.style.marginBottom = '30px';
                                // Gold border override
                                weekDiv.style.border = '2px solid #ecc94b';
                                weekDiv.style.padding = '15px';
                                weekDiv.style.borderRadius = '8px';
                                weekDiv.style.background = '#1a1a1a';

                                const h4 = document.createElement('h3');
                                h4.style.textAlign = 'center';
                                h4.style.color = '#ecc94b';
                                h4.style.marginBottom = '15px';
                                h4.innerHTML = `Sedmica ${idx + 1} <small style='color:#ccc; font-weight:normal; font-size:0.7em;'>(Početak sedmice: ${weekStart})</small>`;
                                weekDiv.appendChild(h4);

                                // Build Grid Table
                                const table = document.createElement('table');
                                table.className = 'schedule-table';
                                table.style.width = '100%';
                                table.style.borderCollapse = 'collapse';

                                // Header
                                const thead = document.createElement('thead');
                                const trH = document.createElement('tr');
                                ['Vrijeme', 'Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak', 'Subota'].forEach(h => {
                                    const th = document.createElement('th');
                                    th.textContent = h;
                                    th.style.borderBottom = '1px solid #ecc94b';
                                    trH.appendChild(th);
                                });
                                thead.appendChild(trH);
                                table.appendChild(thead);

                                // Body
                                const tbody = document.createElement('tbody');
                                // Get unique time slots for this week
                                const slots = Array.from(new Set(events.map(e => e.start + '-' + e.end))).sort();

                                slots.forEach(slot => {
                                    const tr = document.createElement('tr');
                                    const tdTime = document.createElement('td');
                                    tdTime.textContent = slot;
                                    tdTime.classList.add('no-drag');
                                    tr.appendChild(tdTime);

                                    const getDayIdx = (dayStr) => {
                                        const map = {
                                            'Ponedjeljak': 1,
                                            'Utorak': 2,
                                            'Srijeda': 3,
                                            'Četvrtak': 4,
                                            'Petak': 5,
                                            'Subota': 6,
                                            'Nedjelja': 7
                                        };
                                        return map[dayStr] || 0;
                                    };

                                    for (let d = 1; d <= 6; d++) {
                                        const td = document.createElement('td');
                                        const cellEvs = events.filter(e => getDayIdx(e.day) === d && (e.start + '-' + e.end) === slot);

                                        if (cellEvs.length > 0) {
                                            td.innerHTML = cellEvs.map(e => `<strong style="color:#ecc94b">${e.course}</strong><br>(${e.room})`).join('<br>'); // Highlight course
                                            // Make cell border subtle but distinct
                                            td.style.border = '1px solid #444';
                                        }
                                        tr.appendChild(td);
                                    }
                                    tbody.appendChild(tr);
                                });

                                table.appendChild(tbody);
                                weekDiv.appendChild(table);
                                container.appendChild(weekDiv);
                            });
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            const cType = document.getElementById('coll-type-select');
                            const cSem = document.getElementById('coll-sem-select');
                            if (cType) cType.addEventListener('change', renderColloquiums);
                            if (cSem) cSem.addEventListener('change', renderColloquiums);
                        });
                    </script>
                    <?php
                    break;

                case 'zauzetost':
                    // 1. Get Academic Year
                    $year_id = 0;
                    $year_label = "Nije definisana";
                    $stmtYear = $pdo->query("SELECT id, year_label FROM academic_year WHERE is_active = TRUE LIMIT 1");
                    if ($y = $stmtYear->fetch()) {
                        $year_id = $y['id'];
                        $year_label = $y['year_label'];
                    }

                    // 2. Define Time Slots
                    $slots = [
                        ['08:15', '09:00'], ['09:15', '10:00'], ['10:15', '11:00'],
                        ['11:15', '12:00'], ['12:15', '13:00'], ['13:15', '14:00'],
                        ['14:15', '15:00'], ['15:15', '16:00'], ['16:15', '17:00'],
                        ['17:15', '18:00'], ['18:15', '19:00'], ['19:15', '20:00'],
                        ['20:15', '21:00']
                    ];

                    // 3. Get Rooms
                    $rooms = $occupancyService->getRooms();

                    // 4. Get Occupancy Data
                    $occupancy = ($year_id > 0) ? $occupancyService->getOccupancy($year_id) : [];

                    $days = [
                        1 => 'PONEDJELJAK',
                        2 => 'UTORAK',
                        3 => 'SRIJEDA',
                        4 => 'ČETVRTAK',
                        5 => 'PETAK'
                    ];
                    ?>

                    <div class="occupancy-header">
                        <h2>Zauzetost sala - Akademska godina: <?= htmlspecialchars($year_label) ?></h2>
                        <p class="info-text">Kliknite na polje (ili prevucite preko više polja) da biste rezervisali
                            termin za fakultet.</p>
                    </div>

                    <div class="legend-container">
                        <div class="legend-item">
                            <div class="legend-color faculty-fit"></div>
                            <span>FIT</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-feb"></div>
                            <span>FEB</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-mts"></div>
                            <span>MTS</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-pf"></div>
                            <span>PF</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-fsj"></div>
                            <span>FSJ</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-fvu"></div>
                            <span>FVU</span></div>
                    </div>

                    <div class="occupancy-container" id="occupancy-grid-container">
                        <table class="occupancy-table" id="occupancy-table">
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
                                                data-room-id="<?= $room['id'] ?>"
                                                data-weekday="<?= $dayNum ?>"
                                                data-start="<?= $slot[0] ?>"
                                                data-end="<?= $slot[1] ?>"
                                                data-faculty="<?= $occ ? htmlspecialchars($occ['faculty_code']) : '' ?>"
                                                title="<?= $occ ? "Zauzeto: {$occ['faculty_code']} ({$occ['source_type']})" : "Slobodno" ?>">
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

                    <!-- Occupancy Modal -->
                    <div id="occupancyModal" class="occupancy-modal">
                        <div class="occupancy-modal-content">
                            <h3>Upravljanje terminom</h3>
                            <p id="modal-selection-info"></p>
                            <form id="occupancyForm" method="post">
                                <input type="hidden" name="action" value="save_occupancy">
                                <input type="hidden" name="academic_year_id" value="<?= $year_id ?>">
                                <input type="hidden" name="selections" id="selections-input">

                                <label for="faculty_code_select">Odaberite fakultet:</label>
                                <select name="faculty_code" id="faculty_code_select" class="form-control"
                                        style="margin-bottom: 15px;" required>
                                    <option value="" selected disabled>-- Odaberite fakultet --</option>
                                    <option value="FIT">FIT (Fakultet za informacione tehnologije)</option>
                                    <option value="FEB">FEB (Fakultet za ekonomiju i biznis)</option>
                                    <option value="MTS">MTS (Fakultet za mediteranske poslovne studije)</option>
                                    <option value="PF">PF (Pravni fakultet)</option>
                                    <option value="FSJ">FSJ (Fakultet za strane jezike)</option>
                                    <option value="FVU">FVU (Fakultet vizuelnih umjetnosti)</option>
                                </select>

                                <div id="fit-warning"
                                     style="display:none; color: #ffa500; font-size: 0.8rem; margin-bottom: 10px;">
                                    Napomena: Promjene za FIT je preporučljivo vršiti kroz automatski generator
                                    rasporeda.
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="button" class="btn btn-secondary" style="flex: 1;"
                                            onclick="closeOccupancyModal()">Otkaži
                                    </button>
                                    <button type="button" id="btn-delete" class="btn btn-danger"
                                            style="flex: 1; background: #dc3545; border: none; color: white;">Obriši
                                    </button>
                                    <button type="submit" class="btn btn-primary"
                                            style="flex: 1; background: var(--accent); border: none;">Sačuvaj
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const table = document.getElementById('occupancy-table');
                            const cells = table.querySelectorAll('.occupancy-cell');
                            const modal = document.getElementById('occupancyModal');
                            const facultySelect = document.getElementById('faculty_code_select');
                            const btnDelete = document.getElementById('btn-delete');
                            const form = document.getElementById('occupancyForm');
                            const btnSave = form.querySelector('button[type="submit"]');

                            // Initial state: Disable save button
                            btnSave.disabled = true;

                            // Enable save only when a faculty is selected
                            facultySelect.addEventListener('change', function () {
                                if (this.value) {
                                    btnSave.disabled = false;
                                } else {
                                    btnSave.disabled = true;
                                }
                            });

                            btnDelete.addEventListener('click', function () {
                                // To remove reservation, we submit with EMPTY faculty code.
                                // But since select is required, we must disable validation or remove attribute momentarily
                                facultySelect.removeAttribute('required');
                                facultySelect.value = "";
                                form.submit();
                            });
                            const fitWarning = document.getElementById('fit-warning');
                            const selectionsInput = document.getElementById('selections-input');
                            const infoText = document.getElementById('modal-selection-info');

                            let isMouseDown = false;
                            let startCell = null;
                            let selectedCells = [];

                            cells.forEach(cell => {
                                cell.addEventListener('mousedown', function (e) {
                                    isMouseDown = true;
                                    startCell = this;
                                    clearSelection();
                                    toggleCellSelection(this);
                                });

                                cell.addEventListener('mouseenter', function () {
                                    if (isMouseDown) {
                                        toggleCellSelection(this);
                                    }
                                });
                            });

                            document.addEventListener('mouseup', function () {
                                if (isMouseDown) {
                                    isMouseDown = false;
                                    if (selectedCells.length > 0) {
                                        openOccupancyModal();
                                    }
                                }
                            });

                            function toggleCellSelection(cell) {
                                if (!selectedCells.includes(cell)) {
                                    selectedCells.push(cell);
                                    cell.classList.add('selected');
                                }
                            }

                            function clearSelection() {
                                selectedCells.forEach(c => c.classList.remove('selected'));
                                selectedCells = [];
                            }

                            function openOccupancyModal() {
                                const data = selectedCells.map(c => ({
                                    room_id: c.dataset.roomId,
                                    weekday: c.dataset.weekday,
                                    start: c.dataset.start,
                                    end: c.dataset.end
                                }));

                                selectionsInput.value = JSON.stringify(data);
                                infoText.innerText = `Odabrali ste ${selectedCells.length} termin(a).`;

                                // Preset the faculty if only one cell is selected and it's already occupied
                                if (selectedCells.length === 1) {
                                    facultySelect.value = selectedCells[0].dataset.faculty || "";
                                } else {
                                    facultySelect.value = "";
                                }

                                // Trigger change to update button state and warnings
                                facultySelect.dispatchEvent(new Event('change'));
                                modal.style.display = 'block';
                            }

                            window.closeOccupancyModal = function () {
                                modal.style.display = 'none';
                                clearSelection();
                            }

                            facultySelect.addEventListener('change', updateFitWarning);

                            function updateFitWarning() {
                                if (facultySelect.value === 'FIT') {
                                    fitWarning.style.display = 'block';
                                } else {
                                    fitWarning.style.display = 'none';
                                }
                            }

                            // Close modal on escape
                            document.addEventListener('keydown', (e) => {
                                if (e.key === 'Escape') closeOccupancyModal();
                            });
                        });
                    </script>
                    <?php
                    break;

                default:
                    // Ako stranica nije pronađena, prikaži početnu
                    echo "<script>window.location.href='?page=pocetna';</script>";
                    break;
                }
                ?>
    </main>

    <footer>
        <p>© <?php echo date('Y'); ?> Raspored Ispita | Admin Panel</p>
    </footer>

    </body>
</html>