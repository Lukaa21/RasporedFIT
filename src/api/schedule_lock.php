<?php

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/dbconnection.php';
// Ensure user is logged in and is ADMIN
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Nemate dozvolu za ovu akciju.'
    ]);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Samo POST zahtevi su dozvoljeni.'
    ]);
    exit;
}

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action']) || !isset($input['is_locked'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Nedostaju potrebni parametri.'
    ]);
    exit;
}

$action = $input['action'];
$is_locked = (bool)$input['is_locked'];
$winter_schedule_id = isset($input['winter_schedule_id']) ? (int)$input['winter_schedule_id'] : 0;
$summer_schedule_id = isset($input['summer_schedule_id']) ? (int)$input['summer_schedule_id'] : 0;

try {
    // Include database connection
    require_once __DIR__ . '/../../config/dbconnection.php';

    // Save lock state to academic_event table
    if ($action === 'toggle_lock') {
        if ($winter_schedule_id <= 0 || $summer_schedule_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Neispravan winter ili summer schedule_id.'
            ]);
            exit;
        }

        // Update locked_by_admin status
        $locked_value = $is_locked ? 1 : 0;
        
        $rows_winter = 0;
        $rows_summer = 0;
        
        // Update zimski raspored (semestri 1, 3, 5)
        // Join with course table to filter by semester
        $stmt_winter = $pdo->prepare("
            UPDATE academic_event ae
            SET locked_by_admin = :locked
            FROM course c
            WHERE ae.course_id = c.id
              AND c.semester IN (1, 3, 5)
              AND ae.schedule_id = :schedule_id
        ");
        $stmt_winter->execute([
            ':locked' => $locked_value,
            ':schedule_id' => $winter_schedule_id
        ]);
        $rows_winter = $stmt_winter->rowCount();
        
        // Update ljetnji raspored (semestri 2, 4, 6) - only if different from winter
        if ($summer_schedule_id !== $winter_schedule_id) {
            $stmt_summer = $pdo->prepare("
                UPDATE academic_event ae
                SET locked_by_admin = :locked
                FROM course c
                WHERE ae.course_id = c.id
                  AND c.semester IN (2, 4, 6)
                  AND ae.schedule_id = :schedule_id
            ");
            $stmt_summer->execute([
                ':locked' => $locked_value,
                ':schedule_id' => $summer_schedule_id
            ]);
            $rows_summer = $stmt_summer->rowCount();
        }
        
        $total_rows_affected = $rows_winter + $rows_summer;

        // Also update config table flag `schedule_locked` so frontend can read global lock state
        try {
            $cfgKey = 'schedule_locked';
            $cfgValue = $is_locked ? '1' : '0';
            // Use upsert pattern compatible with PostgreSQL
            $cfgStmt = $pdo->prepare("INSERT INTO config (\"key\", value) VALUES (:k, :v) ON CONFLICT (\"key\") DO UPDATE SET value = EXCLUDED.value");
            $cfgStmt->execute([':k' => $cfgKey, ':v' => $cfgValue]);
        } catch (PDOException $e) {
            // Don't fail the entire operation if config update fails; just log to error log
            error_log('Failed to update config.schedule_locked: ' . $e->getMessage());
        }

        // Sync occupancy with locked schedule (FIT) so room usage is reflected
        $occupancyNote = '';
        $occupancyDebug = [
            'active_year_id' => null,
            'events_found' => 0,
            'rows_inserted' => 0,
            'schedule_ids' => [],
            'semester_map' => []
        ];
        try {
            $yearStmt = $pdo->query("SELECT id FROM academic_year WHERE is_active = TRUE LIMIT 1");
            $activeYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
            $activeYearId = $activeYear ? (int)$activeYear['id'] : 0;

            if ($activeYearId === 0) {
                $fallbackStmt = $pdo->query("SELECT id FROM academic_year ORDER BY id DESC LIMIT 1");
                $fallbackYear = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                $activeYearId = $fallbackYear ? (int)$fallbackYear['id'] : 0;
            }
            $occupancyDebug['active_year_id'] = $activeYearId ?: null;

            if ($activeYearId > 0) {
                // Ensure source_type constraint accepts SCHEDULE entries
                $allowedTypes = $pdo->query("SELECT DISTINCT source_type FROM room_occupancy")->fetchAll(PDO::FETCH_COLUMN);
                $allowedTypes = array_filter($allowedTypes, static function ($val) {
                    return $val !== null && $val !== '';
                });
                $allowedTypes[] = 'MANUAL';
                $allowedTypes[] = 'SCHEDULE';
                $allowedTypes = array_values(array_unique($allowedTypes));
                $allowedSql = implode(',', array_map([$pdo, 'quote'], $allowedTypes));

                $pdo->exec("ALTER TABLE room_occupancy DROP CONSTRAINT IF EXISTS room_occupancy_source_type_check");
                $pdo->exec("ALTER TABLE room_occupancy ADD CONSTRAINT room_occupancy_source_type_check CHECK (source_type IN ($allowedSql))");

                $facultyCode = 'FIT';
                $sourceType = 'SCHEDULE';

                // Clear previous schedule-based occupancy for FIT in this academic year
                $clearStmt = $pdo->prepare("DELETE FROM room_occupancy WHERE academic_year_id = ? AND faculty_code = ? AND source_type = ?");
                $clearStmt->execute([$activeYearId, $facultyCode, $sourceType]);

                if ($is_locked) {
                    $slots = [
                        ['08:15', '09:00'], ['09:15', '10:00'], ['10:15', '11:00'],
                        ['11:15', '12:00'], ['12:15', '13:00'], ['13:15', '14:00'],
                        ['14:15', '15:00'], ['15:15', '16:00'], ['16:15', '17:00'],
                        ['17:15', '18:00'], ['18:15', '19:00'], ['19:15', '20:00'],
                        ['20:15', '21:00']
                    ];
                    $toMinutes = static function ($timeValue) {
                        if ($timeValue === null) {
                            return null;
                        }
                        $timeStr = substr((string)$timeValue, 0, 5);
                        if (!preg_match('/^(\d{2}):(\d{2})$/', $timeStr, $m)) {
                            return null;
                        }
                        return ((int)$m[1]) * 60 + (int)$m[2];
                    };
                    $semesterMap = [];
                    if ($winter_schedule_id === $summer_schedule_id) {
                        $semesterMap[$winter_schedule_id] = [1, 2, 3, 4, 5, 6];
                    } else {
                        $semesterMap[$winter_schedule_id] = [1, 3, 5];
                        $semesterMap[$summer_schedule_id] = [2, 4, 6];
                    }
                    $occupancyDebug['semester_map'] = $semesterMap;
                    $occupancyDebug['schedule_ids'] = array_values(array_unique(array_filter([$winter_schedule_id, $summer_schedule_id])));

                    $insStmt = $pdo->prepare("
                        INSERT INTO room_occupancy
                        (room_id, weekday, start_time, end_time, faculty_code, source_type, academic_year_id, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
                        ON CONFLICT ON CONSTRAINT uq_room_time_unique
                        DO UPDATE SET
                            faculty_code = EXCLUDED.faculty_code,
                            source_type = EXCLUDED.source_type,
                            is_active = TRUE
                    ");

                    foreach ($semesterMap as $scheduleId => $semesters) {
                        if ($scheduleId <= 0 || empty($semesters)) {
                            continue;
                        }
                        $semPlaceholders = implode(',', array_fill(0, count($semesters), '?'));
                        $eventsStmt = $pdo->prepare("
                            SELECT
                                ae.room_id,
                                CASE
                                    WHEN ae.day ~ '^[0-9]+$' THEN CAST(ae.day AS int)
                                    WHEN lower(ae.day) IN ('ponedeljak', 'ponedjeljak') THEN 1
                                    WHEN lower(ae.day) IN ('utorak') THEN 2
                                    WHEN lower(ae.day) IN ('srijeda', 'sreda') THEN 3
                                    WHEN lower(ae.day) IN ('cetvrtak', 'četvrtak') THEN 4
                                    WHEN lower(ae.day) IN ('petak') THEN 5
                                    WHEN lower(ae.day) IN ('subota') THEN 6
                                    WHEN lower(ae.day) IN ('nedjelja', 'nedelja') THEN 7
                                    ELSE NULL
                                END AS weekday,
                                CAST(ae.starts_at AS time) AS start_time,
                                CAST(ae.ends_at AS time) AS end_time
                            FROM academic_event ae
                            JOIN course c ON ae.course_id = c.id
                            WHERE ae.room_id IS NOT NULL
                              AND ae.type_enum IN ('LECTURE', 'EXERCISE', 'LAB')
                              AND ae.schedule_id = ?
                              AND c.semester IN ($semPlaceholders)
                        ");

                        $eventsStmt->execute(array_merge([$scheduleId], $semesters));
                        $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
                        $occupancyDebug['events_found'] += count($events);

                        foreach ($events as $ev) {
                            $weekday = isset($ev['weekday']) ? (int)$ev['weekday'] : 0;
                            if ($weekday < 1 || $weekday > 7) {
                                continue;
                            }
                            $eventStart = $toMinutes($ev['start_time']);
                            $eventEnd = $toMinutes($ev['end_time']);
                            if ($eventStart === null || $eventEnd === null) {
                                continue;
                            }

                            foreach ($slots as $slot) {
                                $slotStart = $toMinutes($slot[0]);
                                $slotEnd = $toMinutes($slot[1]);
                                if ($slotStart === null || $slotEnd === null) {
                                    continue;
                                }
                                // overlap check: slot intersects event interval
                                if ($slotStart < $eventEnd && $slotEnd > $eventStart) {
                                    $insStmt->execute([
                                        (int)$ev['room_id'],
                                        $weekday,
                                        $slot[0],
                                        $slot[1],
                                        $facultyCode,
                                        $sourceType,
                                        $activeYearId
                                    ]);
                                    $occupancyDebug['rows_inserted']++;
                                }
                            }
                        }
                    }
                }
            } else {
                $occupancyNote = ' (Napomena: nema aktivne akademske godine za zauzetost sala.)';
            }
        } catch (PDOException $e) {
            $occupancyNote = ' (Napomena: zauzetost sala nije ažurirana: ' . $e->getMessage() . ')';
        }

        // Build appropriate message
        if ($winter_schedule_id === $summer_schedule_id) {
            $message = $is_locked 
                ? 'Raspored ID ' . $winter_schedule_id . ' (' . $rows_winter . ' termina) je zaključan.' 
                : 'Raspored ID ' . $winter_schedule_id . ' (' . $rows_winter . ' termina) je otključan.';
        } else {
            $message = $is_locked 
                ? 'Zimski raspored ID ' . $winter_schedule_id . ' (' . $rows_winter . ' termina) i Ljetnji raspored ID ' . $summer_schedule_id . ' (' . $rows_summer . ' termina) su zaključani.' 
                : 'Zimski raspored ID ' . $winter_schedule_id . ' (' . $rows_winter . ' termina) i Ljetnji raspored ID ' . $summer_schedule_id . ' (' . $rows_summer . ' termina) su otključani.';
        }
        $message .= $occupancyNote;

        echo json_encode([
            'success' => true,
            'message' => $message,
            'is_locked' => $is_locked,
            'winter_schedule_id' => $winter_schedule_id,
            'summer_schedule_id' => $summer_schedule_id,
            'winter_rows_affected' => $rows_winter,
            'summer_rows_affected' => $rows_summer,
            'total_rows_affected' => $total_rows_affected,
            'occupancy_debug' => $occupancyDebug
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Nepoznata akcija: ' . $action
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Greška baze podataka: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Greška: ' . $e->getMessage()
    ]);
}
?>
