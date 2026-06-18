<?php
// log.php — Osiris Engine: Focused Prediction Control Room
require_once 'auth_guard.php'; // redirects to index.php if not logged in; starts the session itself
require_once 'db_config.php';
date_default_timezone_set('Asia/Manila');

$message = "";
$messageType = "info"; // info | error | success
$displayDate = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch latest log entry
$latestStmt = $pdo->query("SELECT * FROM cycle_logs ORDER BY start_date DESC LIMIT 1");
$latestLog = $latestStmt->fetch();

$periodIsActive = false;
if ($latestLog && is_null($latestLog['period_length'])) {
    $daysSinceStart = (new DateTime())->diff(new DateTime($latestLog['start_date']))->days;
    if ($daysSinceStart <= 10) {
        $periodIsActive = true;
    }
}

// ==========================================
// 1. OPERATION ACTIONS (LOGGING & DELETING)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("CSRF token validation failed.");
    }

    // Action A1: Start Period Today
    if (isset($_POST['start_period_today'])) {
        $todayStr = date('Y-m-d');
        try {
            $pdo->beginTransaction();
            
            $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM cycle_logs WHERE start_date = ?");
            $dupCheck->execute([$todayStr]);
            if ($dupCheck->fetchColumn() > 0) throw new Exception("An entry is already logged for today.");

            $prevStmt = $pdo->query("SELECT start_date FROM cycle_logs ORDER BY start_date DESC LIMIT 1");
            $prevLog = $prevStmt->fetch();
            $computed_length = null;
            if ($prevLog) {
                $diff = (new DateTime($prevLog['start_date']))->diff(new DateTime($todayStr))->days;
                if ($diff >= 15) $computed_length = $diff;
            }

            $pdo->prepare("INSERT INTO cycle_logs (start_date, cycle_length, period_length) VALUES (?, ?, NULL)")
                ->execute([$todayStr, $computed_length]);
                
            $pdo->commit();
            $message = "Period started today successfully!";
            $messageType = "success";
            header("Location: log.php#log-tracker");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = $e->getMessage();
            $messageType = "error";
        }
    }

    // Action A2: End Period Today
    if (isset($_POST['end_period_today']) && $periodIsActive) {
        $todayStr = date('Y-m-d');
        try {
            $pdo->beginTransaction();
            
            $startDate = new DateTime($latestLog['start_date']);
            $endDate = new DateTime($todayStr);
            
            $pl = $startDate->diff($endDate)->days + 1;
            if ($pl < 1 || $pl > 10) {
                throw new Exception("Calculated period length ($pl days) must be between 1 and 10 days.");
            }

            $pdo->prepare("UPDATE cycle_logs SET period_length = ? WHERE id = ?")
                ->execute([$pl, $latestLog['id']]);
                
            $pdo->commit();
            $message = "Period ended today. Recorded length: $pl days.";
            $messageType = "success";
            header("Location: log.php#log-tracker");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = $e->getMessage();
            $messageType = "error";
        }
    }

    // Action B: Manual log with custom dates
    if (isset($_POST['manual_start_date'])) {
        $manualDate = trim($_POST['manual_start_date']);
        $periodEnd  = trim($_POST['manual_end_date'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $manualDate)) {
            $message = "Invalid date format. Use YYYY-MM-DD.";
            $messageType = "error";
        } else {
            try {
                $pdo->beginTransaction();
                $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM cycle_logs WHERE start_date = ?");
                $dupCheck->execute([$manualDate]);
                if ($dupCheck->fetchColumn() > 0) throw new Exception("An entry already exists for $manualDate.");

                $prevStmt = $pdo->prepare("SELECT start_date FROM cycle_logs WHERE start_date < ? ORDER BY start_date DESC LIMIT 1");
                $prevStmt->execute([$manualDate]);
                $prevLog = $prevStmt->fetch();
                $computed_length = null;
                if ($prevLog) {
                    $diff = (new DateTime($prevLog['start_date']))->diff(new DateTime($manualDate))->days;
                    if ($diff >= 15) $computed_length = $diff;
                }

                $period_length = null;
                if ($periodEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd)) {
                    $pl = (new DateTime($manualDate))->diff(new DateTime($periodEnd))->days + 1;
                    if ($pl >= 1 && $pl <= 10) $period_length = $pl;
                }

                $pdo->prepare("INSERT INTO cycle_logs (start_date, cycle_length, period_length) VALUES (?, ?, ?)")
                    ->execute([$manualDate, $computed_length, $period_length]);

                $nextStmt = $pdo->prepare("SELECT id, start_date FROM cycle_logs WHERE start_date > ? ORDER BY start_date ASC LIMIT 1");
                $nextStmt->execute([$manualDate]);
                $nextLog = $nextStmt->fetch();
                if ($nextLog) {
                    $newDiff = (new DateTime($manualDate))->diff(new DateTime($nextLog['start_date']))->days;
                    $pdo->prepare("UPDATE cycle_logs SET cycle_length = ? WHERE id = ?")->execute([$newDiff, $nextLog['id']]);
                }

                $pdo->commit();
                $message = "Manual entry logged successfully.";
                $messageType = "success";
                $redirMonth = substr($manualDate, 0, 7);
                header("Location: log.php?cal_month=" . $redirMonth . "#calendar-card");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = $e->getMessage();
                $messageType = "error";
            }
        }
    }

    // Action C: Delete entry and heal chain
    if (isset($_POST['delete_log_id'])) {
        $deleteId = intval($_POST['delete_log_id']);
        $pdo->prepare("DELETE FROM cycle_logs WHERE id = ?")->execute([$deleteId]);
        $allLogs = $pdo->query("SELECT id, start_date FROM cycle_logs ORDER BY start_date ASC")->fetchAll();
        for ($i = 1; $i < count($allLogs); $i++) {
            $newDiff = (new DateTime($allLogs[$i-1]['start_date']))->diff(new DateTime($allLogs[$i]['start_date']))->days;
            $pdo->prepare("UPDATE cycle_logs SET cycle_length = ? WHERE id = ?")->execute([$newDiff, $allLogs[$i]['id']]);
        }
        if (!empty($allLogs)) {
            $pdo->prepare("UPDATE cycle_logs SET cycle_length = NULL WHERE id = ?")->execute([$allLogs[0]['id']]);
        }
        $message = "Record removed. History recalculated.";
        $messageType = "success";
        header("Location: log.php#history-card");
        exit;
    }
}

// ==========================================
// 2. MATHEMATICAL ENGINE
// ==========================================
$limit      = 6;
$page       = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset     = ($page - 1) * $limit;
$totalLogs  = $pdo->query("SELECT COUNT(*) FROM cycle_logs")->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $limit));

$historyStmt = $pdo->prepare("SELECT * FROM cycle_logs ORDER BY start_date DESC LIMIT ? OFFSET ?");
$historyStmt->bindValue(1, $limit, PDO::PARAM_INT);
$historyStmt->bindValue(2, $offset, PDO::PARAM_INT);
$historyStmt->execute();
$logs = $historyStmt->fetchAll();

$allData = $pdo->query("SELECT * FROM cycle_logs ORDER BY start_date ASC")->fetchAll();

$hasEnoughData  = ($totalLogs >= 3);
$cycleLengths   = array_filter(array_column($allData, 'cycle_length'), fn($v) => $v !== null && $v >= 15);
$periodLengths  = array_filter(array_column($allData, 'period_length'), fn($v) => $v !== null && $v >= 1);

$avgCycle   = count($cycleLengths) ? round(array_sum($cycleLengths) / count($cycleLengths), 1) : 28;
$avgPeriod  = count($periodLengths) ? round(array_sum($periodLengths) / count($periodLengths), 1) : 5;

function sd($arr) {
    if (empty($arr) || count($arr) < 2) return 0;
    $mean = array_sum($arr) / count($arr);
    $sq   = array_map(fn($v) => ($v - $mean) ** 2, $arr);
    return round(sqrt(array_sum($sq) / count($sq)), 2);
}
$sdCycle  = sd(array_values($cycleLengths));
$sdPeriod = sd(array_values($periodLengths));

$regScore = 95; $regLabel = 'Very regular';
if ($sdCycle > 7)      { $regScore = 20; $regLabel = 'Irregular'; }
elseif ($sdCycle > 4)  { $regScore = 55; $regLabel = 'Somewhat irregular'; }
elseif ($sdCycle > 2)  { $regScore = 78; $regLabel = 'Fairly regular'; }

$lutealPhase    = 14;
$ovulationDay   = round($avgCycle - $lutealPhase);
$fertileStart   = $ovulationDay - 4;
$fertileEnd     = $ovulationDay + 1;

$lastEntry = !empty($allData) ? end($allData) : null;
$lastStart = $lastEntry ? new DateTime($lastEntry['start_date']) : new DateTime();

$predictions = [];
$nxt = clone $lastStart;
$nxt->modify('+' . round($avgCycle) . ' days');
$cutoff = (new DateTime())->modify('+6 months');
for ($i = 0; $i < 6 && $nxt <= $cutoff; $i++) {
    $pEnd   = clone $nxt; $pEnd->modify('+' . (round($avgPeriod) - 1) . ' days');
    $ovDay  = clone $nxt; $ovDay->modify('+' . ($ovulationDay - 1) . ' days');
    $fStart = clone $nxt; $fStart->modify('+' . ($fertileStart - 1) . ' days');
    $fEnd   = clone $nxt; $fEnd->modify('+' . ($fertileEnd - 1) . ' days');
    $margin = max(1, round($sdCycle));
    $predictions[] = [
        'start'   => clone $nxt,
        'end'     => $pEnd,
        'ovDay'   => $ovDay,
        'fertStart'=> $fStart,
        'fertEnd' => $fEnd,
        'margin'  => $margin,
    ];
    $nxt->modify('+' . round($avgCycle) . ' days');
}

$confScore = min(99, round(50 + count($cycleLengths) * 8 - ($sdCycle * 3)));

function getDayProfile($dateStr, $avgCycle, $sdCycle, $avgPeriod, $ovulationDay, $predictions, $lastStart) {
    $d = new DateTime($dateStr);
    $cycleLen = max(1, round($avgCycle));

    // Build the full list of candidate cycle-start anchors: the real last
    // logged period start, plus every predicted future start. Without the
    // real last start in this list, any date that falls inside the CURRENT
    // (already-logged) cycle gets incorrectly anchored to the first future
    // prediction instead, producing a wrong cycle day and wrong phase/scores.
    $anchors = [clone $lastStart];
    foreach ($predictions as $p) {
        $anchors[] = $p['start'];
    }

    // Pick whichever anchor places the date in the smallest non-negative
    // cycle-day range, i.e. the most recent anchor on or before the date.
    // If the date is before every anchor (rare: very early historical date),
    // fall back to the closest anchor overall.
    $nearestStart = null; $minForwardDist = PHP_INT_MAX;
    $closestOverall = null; $minAbsDist = PHP_INT_MAX;

    foreach ($anchors as $start) {
        $signedDays = (int)$start->diff($d)->days * ($d < $start ? -1 : 1);

        if ($signedDays >= 0 && $signedDays < $minForwardDist) {
            $minForwardDist = $signedDays;
            $nearestStart = $start;
        }
        $absDist = abs($signedDays);
        if ($absDist < $minAbsDist) {
            $minAbsDist = $absDist;
            $closestOverall = $start;
        }
    }
    if ($nearestStart === null) $nearestStart = $closestOverall;
    if ($nearestStart === null) return null;

    $cycleDay  = (int)$nearestStart->diff($d)->days + 1;
    $totalDays = $cycleLen;

    // If the date falls past the end of this anchor's cycle (e.g. the
    // current cycle is already running long), keep advancing the anchor
    // forward by the average cycle length so cycleDay stays within a
    // sensible 1..cycleLen-ish range instead of growing unbounded.
    while ($cycleDay > $cycleLen && $cycleDay - $cycleLen >= 1) {
        $nextAnchor = (clone $nearestStart)->modify('+' . $cycleLen . ' days');
        if ($nextAnchor > $d) break;
        $nearestStart = $nextAnchor;
        $cycleDay = (int)$nearestStart->diff($d)->days + 1;
    }

    // Ovulatory is checked FIRST and wins any overlap. ovulationDay is derived
    // independently from avgPeriod (avgCycle - 14), so nothing guarantees the
    // ovulatory window (ovulationDay-4 .. ovulationDay+1) starts after the
    // menstruation window ends. If menstruation were checked first, a longer
    // logged period (or shorter cycle) could swallow the ovulatory window
    // entirely — including the exact peak day — so fertility would never
    // reach 100%, no matter how many cycles get logged.
    $phase = 'luteal'; $phaseColor = '#AFA9EC'; $phaseLabel = 'Luteal';
    if ($cycleDay >= $ovulationDay - 4 && $cycleDay <= $ovulationDay + 1) { $phase = 'ovulatory'; $phaseColor = '#5DCAA5'; $phaseLabel = 'Ovulatory'; }
    elseif ($cycleDay >= 1 && $cycleDay <= round($avgPeriod))               { $phase = 'menstruation'; $phaseColor = '#ED93B1'; $phaseLabel = 'Menstruation'; }
    elseif ($cycleDay > round($avgPeriod) && $cycleDay < $ovulationDay - 4) { $phase = 'follicular'; $phaseColor = '#FAC775'; $phaseLabel = 'Follicular'; }

    // --- PERIOD RISK ---
    // Risk should peak on the day(s) a period is predicted to start, and
    // taper off the further away (in either direction) the checked date is
    // from the *nearest* predicted/actual start — including the START OF
    // THE NEXT CYCLE, not just the end of the current one.
    //
    // Previously this modeled risk as two competing Gaussian bumps: one
    // centered at cycleDay == totalDays (end of the CURRENT cycle) and one
    // at cycleDay == 1 with a hard amplitude cap of 5. But getDayProfile
    // re-anchors cycleDay back to 1 as soon as a date crosses into a new
    // predicted cycle (see the anchor-advancing loop above) — so by the time
    // a date actually IS the predicted start day, cycleDay is already 1, not
    // totalDays, and the day-32-style bump (amplitude 90) never fires. Only
    // the day-1 bump fires, but it's capped at 5, so the start day of a
    // freshly-anchored cycle could never show high period risk no matter how
    // confident the prediction was.
    //
    // Fix: compute the actual signed day-distance from the checked date to
    // the single nearest predicted/actual cycle start (which "nearestStart"
    // already represents after the anchor-advancing loop), then place ONE
    // Gaussian bump there with a real amplitude that reaches ~98% on the
    // start day itself and tapers over roughly sdCycle days on either side.
    $daysFromStart = (int)$nearestStart->diff($d)->days * ($d < $nearestStart ? -1 : 1);
    $riskSigma = max(1.5, $sdCycle);
    $gauss = fn($x, $mu, $sig) => exp(-0.5 * (($x - $mu) / max(1, $sig)) ** 2);
    $periodRisk = min(98, round($gauss($daysFromStart, 0, $riskSigma) * 98));

    $fertScore = 2;
    if ($phase === 'ovulatory') {
        // $ovulationDay comes from round($avgCycle - $lutealPhase), and round()
        // in PHP always returns a float (e.g. 18.0), even for whole numbers.
        // $cycleDay is an int, so abs($cycleDay - $ovulationDay) is ALWAYS a
        // float (0.0, 1.0, 2.0...). The strict === checks below compare that
        // float against int literals (0, 1, 2...), and PHP's === checks type
        // as well as value, so 0.0 === 0 is false. Every comparison failed,
        // every date fell through to the final ": 12" fallback regardless of
        // how close cycleDay actually was to ovulationDay. Casting to (int)
        // here makes the comparisons behave as intended.
        $dOv = (int) abs($cycleDay - $ovulationDay);
        $fertScore = $dOv === 0 ? 100 : ($dOv === 1 ? 82 : ($dOv === 2 ? 62 : ($dOv === 3 ? 40 : ($dOv === 4 ? 25 : 12))));
    } elseif ($phase === 'follicular' && $cycleDay >= $ovulationDay - 6) {
        $fertScore = max(5, 35 - ($ovulationDay - $cycleDay) * 6);
    } elseif ($phase === 'menstruation') {
        $fertScore = $cycleDay >= $avgPeriod - 1 ? 8 : 2;
    }

    return compact('cycleDay','totalDays','phase','phaseColor','phaseLabel','periodRisk','fertScore','nearestStart');
}

$lookupProfile = null;
$sanitizedCheckDate = '';
if (isset($_GET['check_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['check_date'])) {
    try {
        $testDate = new DateTime($_GET['check_date']);
        $sanitizedCheckDate = $testDate->format('Y-m-d');
        $displayDate = $testDate->format('F j, Y');
        $lookupProfile = getDayProfile($sanitizedCheckDate, $avgCycle, $sdCycle, $avgPeriod, $ovulationDay, $predictions, $lastStart);
    } catch (Exception $e) {
        $lookupProfile = null;
    }
}

// Sanitized prefill values for the manual entry form, set by a calendar day click
$prefillStart = '';
$prefillEnd   = '';
if (isset($_GET['prefill_start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prefill_start'])) {
    $prefillStart = $_GET['prefill_start'];
}
if (isset($_GET['prefill_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['prefill_end'])) {
    $prefillEnd = $_GET['prefill_end'];
}

// ==========================================
// 3. CALENDAR MONTH GRID
// ==========================================
$calMonthParam = $_GET['cal_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $calMonthParam)) {
    $calMonthParam = date('Y-m');
}
$calMonthDate = DateTime::createFromFormat('Y-m-d', $calMonthParam . '-01');
if (!$calMonthDate) { $calMonthDate = new DateTime('first day of this month'); }
$calMonthDate->setTime(0, 0, 0);

$prevMonth = (clone $calMonthDate)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $calMonthDate)->modify('+1 month')->format('Y-m');
$calMonthLabel = $calMonthDate->format('F Y');

// Set of actual logged dates (and their period ranges) for quick lookup
$loggedStartDates = [];
$loggedPeriodDates = [];
foreach ($allData as $row) {
    $loggedStartDates[$row['start_date']] = true;
    if (!empty($row['period_length'])) {
        $d = new DateTime($row['start_date']);
        for ($k = 0; $k < (int)$row['period_length']; $k++) {
            $loggedPeriodDates[$d->format('Y-m-d')] = true;
            $d->modify('+1 day');
        }
    } elseif ($latestLog && $row['id'] == $latestLog['id'] && $periodIsActive) {
        // active, unterminated period: shade from start through today
        $d = new DateTime($row['start_date']);
        $today = new DateTime('today');
        while ($d <= $today) {
            $loggedPeriodDates[$d->format('Y-m-d')] = true;
            $d->modify('+1 day');
        }
    }
}

$firstOfMonth = (clone $calMonthDate);
$daysInMonth  = (int)$firstOfMonth->format('t');
$startWeekday = (int)$firstOfMonth->format('w'); // 0=Sun
$todayStr2    = date('Y-m-d');

$calendarCells = [];
// leading blanks
for ($b = 0; $b < $startWeekday; $b++) {
    $calendarCells[] = null;
}
for ($d = 1; $d <= $daysInMonth; $d++) {
    $cellDateObj = (clone $firstOfMonth)->modify('+' . ($d - 1) . ' days');
    $cellDateStr = $cellDateObj->format('Y-m-d');
    $profile = getDayProfile($cellDateStr, $avgCycle, $sdCycle, $avgPeriod, $ovulationDay, $predictions, $lastStart);
    $calendarCells[] = [
        'dateStr'      => $cellDateStr,
        'dayNum'       => $d,
        'isToday'      => ($cellDateStr === $todayStr2),
        'isLogged'     => isset($loggedStartDates[$cellDateStr]),
        'isPeriodDay'  => isset($loggedPeriodDates[$cellDateStr]),
        'profile'      => $profile,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Osiris — Prediction Engine</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:       #0d0d0f;
            --surface:  #141416;
            --surface2: #1a1a1e;
            --border:   rgba(255,255,255,0.07);
            --border2:  rgba(255,255,255,0.12);
            --txt1:     #f0eff4;
            --txt2:     #8a8a9a;
            --txt3:     #55556a;
            --accent:   #c084fc;
            --red:      #f87171;
            --teal:     #34d399;
            --amber:    #fbbf24;
            --pink:     #f9a8d4;
            --purple:   #a78bfa;
            --radius:   12px;
            --radius-sm:8px;
        }
        body { background: var(--bg); color: var(--txt1); font-family: 'Inter', sans-serif; font-size: 14px; line-height: 1.6; min-height: 100vh; padding: 24px 16px 60px; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .wrap { max-width: 680px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; }
        .wrap.split { max-width: 1100px; display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: min-content; gap: 12px; align-items: start; }
        .wrap.split .full-width { grid-column: 1 / -1; }
        .wrap.split .col-left  { grid-column: 1; display: flex; flex-direction: column; gap: 12px; }
        .wrap.split .col-right { grid-column: 2; display: flex; flex-direction: column; gap: 12px; }
        @media (max-width: 860px) {
            .wrap.split { display: flex; flex-direction: column; max-width: 680px; }
            .wrap.split .col-left, .wrap.split .col-right { display: flex; flex-direction: column; width: 100%; grid-column: auto; gap: 12px; }
        }
        .topnav { display: flex; justify-content: space-between; align-items: center; padding: 0 0 8px; }
        .topnav a { color: var(--accent); text-decoration: none; font-size: 12px; }
        .topnav a:hover { text-decoration: underline; }
        .topnav-right { font-size: 11px; color: var(--txt3); font-family: 'JetBrains Mono', monospace; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px 22px; }
        .sec-label { font-size: 10px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: var(--txt3); margin-bottom: 14px; }
        .page-header { padding: 12px 0 4px; }
        .page-header h1 { font-size: 22px; font-weight: 600; color: var(--txt1); }
        .page-header p  { font-size: 12px; color: var(--txt3); margin-top: 3px; letter-spacing: .05em; text-transform: uppercase; }
        .alert { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; border: 1px solid; margin-bottom: 12px; }
        .alert.success { background: rgba(52,211,153,.07); border-color: rgba(52,211,153,.2); color: var(--teal); }
        .alert.error   { background: rgba(248,113,113,.07); border-color: rgba(248,113,113,.2); color: var(--red); }
        .alert.info    { background: rgba(192,132,252,.07); border-color: rgba(192,132,252,.2); color: var(--accent); }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
        .stat-box { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 14px; }
        .stat-box .lbl { font-size: 10px; color: var(--txt3); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
        .stat-box .val { font-size: 20px; font-weight: 600; color: var(--txt1); line-height: 1.2; }
        .stat-box .sub { font-size: 10px; color: var(--txt2); margin-top: 3px; }
        .pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
        .pill.active   { background: rgba(52,211,153,.1); color: var(--teal); border: 1px solid rgba(52,211,153,.25); }
        .pill.standby  { background: rgba(251,191,36,.1);  color: var(--amber); border: 1px solid rgba(251,191,36,.25); }
        .pill.period   { background: rgba(249,168,212,.1); color: var(--pink); border: 1px solid rgba(249,168,212,.25); }
        .pill.menstruation { background: rgba(237,147,177,.1); color: var(--pink); border: 1px solid rgba(237,147,177,.25); }
        .pill.ovulatory{ background: rgba(52,211,153,.15); color: #6ee7b7; border: 1px solid rgba(52,211,153,.3); }
        .pill.follicular{background: rgba(251,191,36,.1); color: var(--amber); border: 1px solid rgba(251,191,36,.25);}
        .pill.luteal   { background: rgba(167,139,250,.1); color: var(--purple); border: 1px solid rgba(167,139,250,.25); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 10px; }
        .form-group label { font-size: 11px; color: var(--txt2); font-weight: 500; }
        .form-group input { height: 36px; background: var(--surface2); border: 1px solid var(--border2); border-radius: var(--radius-sm); color: var(--txt1); padding: 0 12px; font-size: 13px; font-family: 'Inter', sans-serif; width: 100%; outline: none; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 38px; padding: 0 18px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; border: 1px solid; cursor: pointer; text-decoration: none; font-family: 'Inter', sans-serif; width: 100%; }
        .btn:active { transform: scale(.98); }
        .btn-ghost  { background: transparent; border-color: var(--border2); color: var(--txt2); }
        .btn-danger { background: rgba(248,113,113,.1); border-color: rgba(248,113,113,.25); color: var(--red); }
        .btn-teal   { background: rgba(52,211,153,.1); border-color: rgba(52,211,153,.25); color: var(--teal); }
        .btn-accent { background: rgba(192,132,252,.12); border-color: rgba(192,132,252,.3); color: var(--accent); }
        .btn-sm { height: 30px; padding: 0 12px; font-size: 12px; width: auto; }
        .score-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .score-label { font-size: 12px; color: var(--txt2); min-width: 118px; }
        .score-track { flex: 1; height: 6px; background: rgba(255,255,255,.06); border-radius: 3px; overflow: hidden; }
        .score-fill  { height: 100%; border-radius: 3px; transition: width .4s ease; }
        .score-num   { font-size: 12px; font-weight: 600; min-width: 36px; text-align: right; font-family: 'JetBrains Mono', monospace; }
        .tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
        .tbl thead th { padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600; color: var(--txt3); text-transform: uppercase; letter-spacing: .07em; border-bottom: 1px solid var(--border); }
        .tbl tbody td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--txt2); }
        .tbl tbody tr:last-child td { border-bottom: none; }
        .upcoming-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .upcoming-item:last-child { border-bottom: none; }
        .upcoming-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--pink); margin-top: 5px; flex-shrink: 0; }
        .upcoming-main { flex: 1; }
        .upcoming-title { font-size: 13px; font-weight: 500; color: var(--txt1); }
        .upcoming-sub   { font-size: 11px; color: var(--txt2); margin-top: 2px; }
        .profile-panel { background: var(--surface2); border: 1px solid var(--border2); border-radius: var(--radius); padding: 18px; margin-top: 12px; }
        .profile-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .profile-date { font-size: 15px; font-weight: 600; }
        .profile-sub  { font-size: 11px; color: var(--txt2); margin-top: 2px; }
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; border-top: 1px solid var(--border); }
        .pagination .pg-info { font-size: 11px; color: var(--txt3); font-family: 'JetBrains Mono', monospace; }
        .pagination .pg-btns { display: flex; gap: 6px; }
        .pg-btn { height: 28px; padding: 0 12px; background: var(--surface2); border: 1px solid var(--border2); border-radius: 6px; color: var(--txt2); font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .pg-btn:hover { border-color: rgba(255,255,255,.2); color: var(--txt1); }
        .pg-btn.disabled { opacity: .25; pointer-events: none; }
        .notice { font-size: 11px; color: var(--txt3); line-height: 1.7; padding: 10px 14px; background: rgba(255,255,255,.02); border-radius: var(--radius-sm); border: 1px solid var(--border); }

        /* Calendar */
        .cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .cal-month-label { font-size: 15px; font-weight: 600; color: var(--txt1); }
        .cal-nav { display: flex; gap: 6px; }
        .cal-nav a { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: var(--surface2); border: 1px solid var(--border2); border-radius: 6px; color: var(--txt2); text-decoration: none; font-size: 13px; }
        .cal-nav a:hover { border-color: rgba(255,255,255,.2); color: var(--txt1); }
        .cal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 6px; }
        .cal-weekdays span { text-align: center; font-size: 9px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--txt3); padding: 4px 0; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .cal-cell { position: relative; aspect-ratio: 1 / 1; border-radius: 8px; border: 1px solid transparent; background: var(--surface2); display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; transition: transform .12s ease, border-color .12s ease; font-family: 'JetBrains Mono', monospace; }
        .cal-cell:hover { border-color: var(--border2); transform: translateY(-1px); }
        .cal-cell.empty { background: transparent; cursor: default; pointer-events: none; }
        .cal-cell .cal-daynum { font-size: 12px; font-weight: 500; color: var(--txt1); z-index: 1; }
        .cal-cell.cal-today { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent) inset; }
        .cal-cell.cal-today .cal-daynum { color: var(--accent); font-weight: 700; }
        .cal-cell.phase-menstruation { background: rgba(237,147,177,.16); }
        .cal-cell.phase-follicular   { background: rgba(250,199,117,.13); }
        .cal-cell.phase-ovulatory    { background: rgba(93,202,165,.18); }
        .cal-cell.phase-luteal       { background: rgba(175,169,236,.13); }
        .cal-cell.cal-logged-period::after {
            content: ''; position: absolute; bottom: 4px; width: 14px; height: 3px; border-radius: 2px; background: var(--red);
        }
        .cal-cell.cal-logged-start { border: 1px solid var(--red); }
        .cal-cell.cal-selected { outline: 2px solid var(--accent); outline-offset: 1px; }
        .cal-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
        .cal-legend-item { display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--txt2); }
        .cal-legend-swatch { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .cal-hint { font-size: 11px; color: var(--txt3); margin-top: 10px; text-align: center; }

        @media (max-width: 480px) {
            .form-row { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: 1fr 1fr; }
            .cal-cell .cal-daynum { font-size: 11px; }
        }
    </style>
</head>
<body>

<div class="wrap split">

    <div class="topnav full-width">
        <a href="index.php">← Dashboard</a>
        <span class="topnav-right mono">logs: <?php echo $totalLogs; ?> · <?php echo date('Y-m-d'); ?> · <a href="logout.php" style="color:var(--txt3)">Log out</a></span>
    </div>

    <div class="page-header full-width">
        <h1>Osiris — Prediction Engine</h1>
        <p>Cycle analytics &amp; fertility intelligence</p>
    </div>

    <div id="alert-zone" class="full-width">
        <?php if (!empty($message)): ?>
        <div class="alert <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-left">
    <div class="card">
        <div class="sec-label">Engine status</div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div>
                <div style="font-size:24px;font-weight:600;color:var(--txt1)">
                    <?php echo $hasEnoughData ? $avgCycle : '—'; ?>
                    <span style="font-size:13px;font-weight:400;color:var(--txt2)"> day avg cycle</span>
                </div>
                <div style="font-size:11px;color:var(--txt2);margin-top:3px">
                    <?php echo $hasEnoughData ? "±$sdCycle day variability · $regLabel" : "Needs " . max(0, 3-$totalLogs) . " more cycle(s) to activate"; ?>
                </div>
            </div>
            <span class="pill <?php echo $hasEnoughData ? 'active' : 'standby'; ?>">
                <?php echo $hasEnoughData ? '● Active' : '○ Calibrating'; ?>
            </span>
        </div>

        <?php if ($hasEnoughData): ?>
        <div class="stat-grid">
            <div class="stat-box">
                <div class="lbl">Avg cycle</div>
                <div class="val"><?php echo $avgCycle; ?></div>
                <div class="sub">±<?php echo $sdCycle; ?> days SD</div>
            </div>
            <div class="stat-box">
                <div class="lbl">Avg period</div>
                <div class="val"><?php echo $avgPeriod; ?></div>
                <div class="sub">±<?php echo $sdPeriod; ?> days SD</div>
            </div>
            <div class="stat-box">
                <div class="lbl">Ovulation est.</div>
                <div class="val">Day <?php echo $ovulationDay; ?></div>
                <div class="sub">of cycle</div>
            </div>
            <div class="stat-box">
                <div class="lbl">Fertile window</div>
                <div class="val">Day <?php echo $fertileStart; ?>–<?php echo $fertileEnd; ?></div>
                <div class="sub">of cycle</div>
            </div>
            <div class="stat-box">
                <div class="lbl">Regularity</div>
                <div class="val"><?php echo $regScore; ?></div>
                <div class="sub">/100 · <?php echo $regLabel; ?></div>
            </div>
            <div class="stat-box">
                <div class="lbl">Confidence</div>
                <div class="val"><?php echo $confScore; ?></div>
                <div class="sub">/100 model score</div>
            </div>
        </div>
        <?php else: ?>
        <div class="notice">
            <strong>Prerequisite:</strong> At least 3 logged cycles are required before predictions activate. Using fallback of 28-day cycle in the meantime.
        </div>
        <?php endif; ?>
    </div>

    <div class="card" id="calendar-card">
        <div class="cal-header">
            <div>
                <div class="sec-label" style="margin-bottom:2px">Live calendar preview</div>
                <div class="cal-month-label"><?php echo $calMonthLabel; ?></div>
            </div>
            <div class="cal-nav">
                <a href="?cal_month=<?php echo $prevMonth; ?>#calendar-card">‹</a>
                <a href="?cal_month=<?php echo date('Y-m'); ?>#calendar-card">•</a>
                <a href="?cal_month=<?php echo $nextMonth; ?>#calendar-card">›</a>
            </div>
        </div>

        <div class="cal-weekdays">
            <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
        </div>
        <div class="cal-grid">
            <?php foreach ($calendarCells as $cell): ?>
                <?php if ($cell === null): ?>
                    <div class="cal-cell empty"></div>
                <?php else: ?>
                    <?php
                        $phaseClass = $cell['profile'] ? 'phase-' . $cell['profile']['phase'] : '';
                        $cls = trim('cal-cell ' . $phaseClass
                            . ($cell['isToday'] ? ' cal-today' : '')
                            . ($cell['isPeriodDay'] ? ' cal-logged-period' : '')
                            . ($cell['isLogged'] ? ' cal-logged-start' : ''));
                        $titleBits = [];
                        if ($cell['profile']) $titleBits[] = $cell['profile']['phaseLabel'] . ' · cycle day ' . $cell['profile']['cycleDay'];
                        if ($cell['isLogged'])    $titleBits[] = 'Logged period start';
                        elseif ($cell['isPeriodDay']) $titleBits[] = 'Logged period day';
                        $titleAttr = htmlspecialchars(implode(' · ', $titleBits));
                    ?>
                    <div class="<?php echo $cls; ?>"
                         data-date="<?php echo $cell['dateStr']; ?>"
                         title="<?php echo $titleAttr; ?>"
                         onclick="onCalDayClick('<?php echo $cell['dateStr']; ?>', this)">
                        <span class="cal-daynum"><?php echo $cell['dayNum']; ?></span>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="cal-legend">
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:rgba(237,147,177,.5)"></span>Menstruation</div>
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:rgba(250,199,117,.5)"></span>Follicular</div>
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:rgba(93,202,165,.5)"></span>Ovulatory</div>
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:rgba(175,169,236,.5)"></span>Luteal</div>
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:transparent;border:1px solid var(--red)"></span>Logged start</div>
            <div class="cal-legend-item"><span class="cal-legend-swatch" style="background:transparent;border:2px solid var(--accent)"></span>Today</div>
        </div>

        <p class="cal-hint">Tap a day to analyse it and prefill the manual entry form below.</p>
    </div>
    </div>

    <div class="col-right">
    <div class="card" id="log-tracker">
        <div class="sec-label">Log Tracker</div>

        <form method="POST" action="log.php#log-tracker">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <?php if ($periodIsActive): ?>
                <div style="background:rgba(52,211,153,.05);border:1px solid rgba(52,211,153,.15);border-radius:var(--radius-sm);padding:14px;margin-bottom:16px; text-align:center;">
                    <div style="font-size:11px;font-weight:600;color:var(--teal);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">
                        Current Status: Period is Active
                    </div>
                    <p style="font-size:12px; color:var(--txt2); margin-bottom:12px;">
                        Started on: <strong><?php echo date('F j, Y', strtotime($latestLog['start_date'])); ?></strong>
                    </p>
                    <button type="submit" name="end_period_today" class="btn btn-teal">
                        ✓ End Period Today
                    </button>
                </div>
            <?php else: ?>
                <div style="background:rgba(248,113,113,.05);border:1px solid rgba(248,113,113,.15);border-radius:var(--radius-sm);padding:14px;margin-bottom:16px;">
                    <div style="font-size:11px;font-weight:600;color:var(--red);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;text-align:center;">
                        Quick Logger
                    </div>
                    <button type="submit" name="start_period_today" class="btn btn-danger">
                        🩸 Start Period Today
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
            <div style="flex:1;height:1px;background:var(--border)"></div>
            <span style="font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.06em">or enter manually</span>
            <div style="flex:1;height:1px;background:var(--border)"></div>
        </div>

        <form method="POST" id="manualForm" action="log.php#log-tracker">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Period start date *</label>
                    <input type="date" name="manual_start_date" id="manualStartInput" value="<?php echo htmlspecialchars($prefillStart); ?>" required>
                </div>
                <div class="form-group">
                    <label>Period end date <span style="color:var(--txt3)">(optional)</span></label>
                    <input type="date" name="manual_end_date" id="manualEndInput" value="<?php echo htmlspecialchars($prefillEnd); ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-accent">Save manual entry</button>
        </form>
    </div>

    <div class="card" id="analysis-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <div class="sec-label" style="margin-bottom:0">Analyse a specific date</div>
            <?php if ($lookupProfile): ?>
                <a href="log.php#analysis-card" class="btn btn-ghost btn-sm" style="color:var(--txt2); border-color:var(--border);">✕ Clear Analysis</a>
            <?php endif; ?>
        </div>
        <form method="GET" id="analyseForm" action="log.php#analysis-card" style="display:flex;gap:8px;align-items:flex-end">
            <div class="form-group" style="flex:1">
                <label>Date to check</label>
                <input type="date" name="check_date" id="checkDateInput" value="<?php echo htmlspecialchars($sanitizedCheckDate ?: date('Y-m-d')); ?>">
            </div>
            <input type="hidden" name="cal_month" value="<?php echo htmlspecialchars($calMonthParam); ?>">
            <input type="hidden" name="prefill_start" id="prefillStartHidden" value="">
            <input type="hidden" name="prefill_end" id="prefillEndHidden" value="">
            <button type="submit" class="btn btn-accent" style="width:auto;">Analyse</button>
        </form>

        <?php if ($lookupProfile): ?>
        <div class="profile-panel">
            <div class="profile-header">
                <div>
                    <div class="profile-date"><?php echo htmlspecialchars($displayDate); ?></div>
                    <div class="profile-sub">Cycle day <?php echo $lookupProfile['cycleDay']; ?> of ~<?php echo $lookupProfile['totalDays']; ?></div>
                </div>
                <span class="pill <?php echo $lookupProfile['phase']; ?>" style="margin-left:auto">
                    <?php echo $lookupProfile['phaseLabel']; ?>
                </span>
            </div>

            <div class="score-row">
                <span class="score-label">Period risk</span>
                <div class="score-track"><div class="score-fill" style="width:<?php echo $lookupProfile['periodRisk']; ?>%;background:#f9a8d4"></div></div>
                <span class="score-num" style="color:#f9a8d4"><?php echo $lookupProfile['periodRisk']; ?>%</span>
            </div>
            <div class="score-row">
                <span class="score-label">Fertility score</span>
                <div class="score-track"><div class="score-fill" style="width:<?php echo $lookupProfile['fertScore']; ?>%;background:#34d399"></div></div>
                <span class="score-num" style="color:#34d399"><?php echo $lookupProfile['fertScore']; ?>%</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($hasEnoughData && !empty($predictions)): ?>
    <div class="card">
        <div class="sec-label">Upcoming periods &amp; fertile windows</div>
        <?php foreach (array_slice($predictions, 0, 4) as $i => $p): ?>
        <div class="upcoming-item">
            <div class="upcoming-dot"></div>
            <div class="upcoming-main">
                <div class="upcoming-title"><?php echo $p['start']->format('M j'); ?> – <?php echo $p['end']->format('M j, Y'); ?></div>
                <div class="upcoming-sub">
                    ±<?php echo $p['margin']; ?> day window ·
                    Ovulation ~<?php echo $p['ovDay']->format('M j'); ?> ·
                    Fertile <?php echo $p['fertStart']->format('M j'); ?>–<?php echo $p['fertEnd']->format('M j'); ?>
                </div>
            </div>
            <span class="pill period" style="flex-shrink:0">Cycle <?php echo $i+1; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    </div>

    <div class="card full-width" id="history-card" style="padding:0;overflow:hidden">
        <div style="padding:16px 22px 14px">
            <div class="sec-label" style="margin-bottom:0">Historical records</div>
        </div>
        <?php if ($totalLogs > 0): ?>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Start date</th>
                    <th>Period end</th>
                    <th>Cycle length</th>
                    <th style="text-align:right;padding-right:16px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="date-cell"><?php echo date('M d, Y', strtotime($log['start_date'])); ?></td>
                    <td>
                        <?php 
                        if (!empty($log['period_length'])) {
                            echo date('M d, Y', strtotime($log['start_date'] . ' +' . ($log['period_length'] - 1) . ' days'));
                        } else {
                            if ($latestLog && $log['id'] == $latestLog['id'] && $periodIsActive) {
                                echo '<span style="color:var(--teal)">Active...</span>';
                            } else {
                                echo '<span style="color:var(--txt3)">—</span>';
                            }
                        }
                        ?>
                    </td>
                    <td class="mono"><?php echo $log['cycle_length'] ? $log['cycle_length'] . ' days' : '—'; ?></td>
                    <td style="text-align:right;padding-right:16px">
                        <form method="POST" action="log.php#history-card" onsubmit="return confirm('Remove this entry?');" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="delete_log_id" value="<?php echo $log['id']; ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--red);border-color:rgba(248,113,113,.2)">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <span class="pg-info">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
            <div class="pg-btns">
                <a href="?page=<?php echo $page-1; ?>#history-card" class="pg-btn <?php if($page<=1) echo 'disabled'; ?>">← Prev</a>
                <a href="?page=<?php echo $page+1; ?>#history-card" class="pg-btn <?php if($page>=$totalPages) echo 'disabled'; ?>">Next →</a>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:40px;text-align:center;color:var(--txt3);font-size:13px">
            No cycle records yet. Log your first period above.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Remember the last-clicked date (in this page view) so a second click within
    // a few seconds on a later date is treated as "set the end date" for quick range entry.
    let lastCalClick = { date: null, time: 0 };

    function onCalDayClick(dateStr, cellEl) {
        const now = Date.now();
        const checkInput  = document.getElementById('checkDateInput');
        const startHidden = document.getElementById('prefillStartHidden');
        const endHidden    = document.getElementById('prefillEndHidden');

        const isQuickSecondClick = lastCalClick.date
            && lastCalClick.date !== dateStr
            && (now - lastCalClick.time) < 8000
            && dateStr > lastCalClick.date;

        if (isQuickSecondClick) {
            // Treat as "this is the end date" for the entry just started.
            startHidden.value = lastCalClick.date;
            endHidden.value   = dateStr;
        } else {
            // Fresh click: this becomes the new period start.
            startHidden.value = dateStr;
            endHidden.value   = '';
            lastCalClick = { date: dateStr, time: now };
        }

        if (checkInput) checkInput.value = dateStr;

        // Single navigation: the server reads check_date + prefill_start/end,
        // computes the analysis panel, and pre-fills the manual entry form —
        // no client-side race with a reload. Land near the manual entry form
        // (instead of the top of the page) since that's the next action.
        const analyseForm = document.getElementById('analyseForm');
        analyseForm.action = 'log.php#log-tracker';
        sessionStorage.setItem('osiris_scroll_to_manual', '1');
        analyseForm.submit();
    }

    // After the reload triggered by a calendar click, scroll the manual entry
    // form into view and give it a brief highlight so the prefill is noticed.
    document.addEventListener('DOMContentLoaded', () => {
        if (sessionStorage.getItem('osiris_scroll_to_manual') === '1') {
            sessionStorage.removeItem('osiris_scroll_to_manual');
            const form = document.getElementById('manualForm');
            const startInput = document.getElementById('manualStartInput');
            const endInput   = document.getElementById('manualEndInput');
            if (form) {
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                [startInput, endInput].forEach(highlightField);
            }
        }
        markSelectedFromInput();
    });

    function markSelectedFromInput() {
        // Highlight the date that the analysis panel is actually showing
        // (the "Date to check" value), not the manual-entry start date —
        // those two can differ during a quick two-click range entry, and
        // highlighting the wrong one is what made the calendar look like
        // it disagreed with the analysis panel.
        const checkInput = document.getElementById('checkDateInput');
        if (!checkInput || !checkInput.value) return;
        const cell = document.querySelector('.cal-cell[data-date="' + checkInput.value + '"]');
        if (cell) cell.classList.add('cal-selected');
    }

    function highlightField(el) {
        if (!el) return;
        el.style.transition = 'none';
        el.style.borderColor = 'var(--accent)';
        el.style.boxShadow = '0 0 0 2px rgba(192,132,252,.25)';
        requestAnimationFrame(() => {
            el.style.transition = 'border-color .6s ease, box-shadow .6s ease';
            setTimeout(() => {
                el.style.borderColor = '';
                el.style.boxShadow = '';
            }, 900);
        });
    }
</script>
</body>
</html>