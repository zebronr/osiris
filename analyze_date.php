<?php
// analyze_date.php — Osiris Engine: Standalone "Analyse a specific date" API endpoint
//
// GET /analyze_date.php?date=YYYY-MM-DD&api_key=YOUR_KEY
//
// Returns JSON with cycle-day / phase / period-risk / fertility-score data,
// plus an `alerts` array with proximity warnings for upcoming period or ovulation.
//
// Alert look-ahead windows (configurable below):
//   ALERT_PERIOD_DAYS_AHEAD    — warn N days before predicted period start
//   ALERT_OVULATION_DAYS_AHEAD — warn N days before predicted ovulation day

header('Content-Type: application/json');

require_once 'db_config.php';
date_default_timezone_set('Asia/Manila');

// ==========================================
// ALERT CONFIGURATION
// ==========================================
const ALERT_PERIOD_DAYS_AHEAD    = 5;   // days before period start to fire a warning (was 3)
const ALERT_OVULATION_DAYS_AHEAD = 3;   // days before ovulation day to fire a warning

// ==========================================
// 0. AUTH
// ==========================================
require_once 'auth_config.php'; // defines OSIRIS_API_KEY

function fail($code, $error) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$providedKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!is_string($providedKey) || $providedKey === '' || !hash_equals(OSIRIS_API_KEY, $providedKey)) {
    fail(401, 'Invalid or missing API key.');
}

// ==========================================
// 1. VALIDATE INPUT
// ==========================================
$dateParam = $_GET['date'] ?? '';
if (!is_string($dateParam) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    fail(400, 'Missing or invalid "date" parameter. Expected format: YYYY-MM-DD.');
}

try {
    $checkDate = new DateTime($dateParam);
    if ($checkDate->format('Y-m-d') !== $dateParam) {
        fail(400, 'Invalid calendar date: ' . htmlspecialchars($dateParam));
    }
} catch (Exception $e) {
    fail(400, 'Invalid date.');
}

// ==========================================
// 2. MATHEMATICAL ENGINE
// ==========================================
$allData = $pdo->query("SELECT * FROM cycle_logs ORDER BY start_date ASC")->fetchAll();
$totalLogs = count($allData);
$hasEnoughData = ($totalLogs >= 3);

$cycleLengths  = array_filter(array_column($allData, 'cycle_length'), fn($v) => $v !== null && $v >= 15);
$periodLengths = array_filter(array_column($allData, 'period_length'), fn($v) => $v !== null && $v >= 1);

$avgCycle  = count($cycleLengths) ? round(array_sum($cycleLengths) / count($cycleLengths), 1) : 28;
$avgPeriod = count($periodLengths) ? round(array_sum($periodLengths) / count($periodLengths), 1) : 5;

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

$lutealPhase  = 14;
$ovulationDay = round($avgCycle - $lutealPhase);
$fertileStart = $ovulationDay - 4;
$fertileEnd   = $ovulationDay + 1;

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
        'start'    => clone $nxt,
        'end'      => $pEnd,
        'ovDay'    => $ovDay,
        'fertStart'=> $fStart,
        'fertEnd'  => $fEnd,
        'margin'   => $margin,
    ];
    $nxt->modify('+' . round($avgCycle) . ' days');
}

$confScore = min(99, round(50 + count($cycleLengths) * 8 - ($sdCycle * 3)));

function getDayProfile($dateStr, $avgCycle, $sdCycle, $avgPeriod, $ovulationDay, $predictions, $lastStart) {
    $d = new DateTime($dateStr);
    $cycleLen = max(1, round($avgCycle));

    $anchors = [clone $lastStart];
    foreach ($predictions as $p) {
        $anchors[] = $p['start'];
    }

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

    while ($cycleDay > $cycleLen && $cycleDay - $cycleLen >= 1) {
        $nextAnchor = (clone $nearestStart)->modify('+' . $cycleLen . ' days');
        if ($nextAnchor > $d) break;
        $nearestStart = $nextAnchor;
        $cycleDay = (int)$nearestStart->diff($d)->days + 1;
    }

    $phase = 'luteal'; $phaseColor = '#AFA9EC'; $phaseLabel = 'Luteal';
    if ($cycleDay >= $ovulationDay - 4 && $cycleDay <= $ovulationDay + 1) { $phase = 'ovulatory'; $phaseColor = '#5DCAA5'; $phaseLabel = 'Ovulatory'; }
    elseif ($cycleDay >= 1 && $cycleDay <= round($avgPeriod))               { $phase = 'menstruation'; $phaseColor = '#ED93B1'; $phaseLabel = 'Menstruation'; }
    elseif ($cycleDay > round($avgPeriod) && $cycleDay < $ovulationDay - 4) { $phase = 'follicular'; $phaseColor = '#FAC775'; $phaseLabel = 'Follicular'; }

    $daysFromStart = (int)$nearestStart->diff($d)->days * ($d < $nearestStart ? -1 : 1);
    $riskSigma = max(1.5, $sdCycle);
    $gauss = fn($x, $mu, $sig) => exp(-0.5 * (($x - $mu) / max(1, $sig)) ** 2);
    $periodRisk = min(98, round($gauss($daysFromStart, 0, $riskSigma) * 98));

    $fertScore = 2;
    if ($phase === 'ovulatory') {
        $dOv = (int) abs($cycleDay - $ovulationDay);
        $fertScore = $dOv === 0 ? 100 : ($dOv === 1 ? 82 : ($dOv === 2 ? 62 : ($dOv === 3 ? 40 : ($dOv === 4 ? 25 : 12))));
    } elseif ($phase === 'follicular' && $cycleDay >= $ovulationDay - 6) {
        $fertScore = max(5, 35 - ($ovulationDay - $cycleDay) * 6);
    } elseif ($phase === 'menstruation') {
        $fertScore = $cycleDay >= $avgPeriod - 1 ? 8 : 2;
    }

    return compact('cycleDay','totalDays','phase','phaseColor','phaseLabel','periodRisk','fertScore','nearestStart');
}

// ==========================================
// 3. RUN THE ANALYSIS FOR THE REQUESTED DATE
// ==========================================
$profile = getDayProfile($dateParam, $avgCycle, $sdCycle, $avgPeriod, $ovulationDay, $predictions, $lastStart);

if ($profile === null) {
    fail(500, 'Could not compute a profile for this date.');
}

// ==========================================
// 4. BUILD ALERTS
//
// Every alert carries a `chance` (0–100) derived from the engine's own scores
// and a `chanceLabel` bucket, so messages are always phrased probabilistically
// ("possible", "likely", "very likely") rather than as certainties.
//
// Chance buckets:
//   0–29   → "low"        — phrased as "possible"
//   30–59  → "moderate"   — phrased as "possible"
//   60–84  → "likely"     — phrased as "likely"
//   85–100 → "very_likely"— phrased as "very likely"
//
// Alert object shape:
//   {
//     "type":         "period" | "ovulation" | "fertile_window",
//     "level":        "today" | "tomorrow" | "upcoming",
//     "daysUntil":    <int>,        // 0 = today
//     "date":         "YYYY-MM-DD",
//     "chance":       <int>,        // 0–100
//     "chanceLabel":  "low" | "moderate" | "likely" | "very_likely",
//     "message":      "<human string>",
//     "marginDays":   <int>         // ±uncertainty (period alerts only)
//   }
// ==========================================

// Helper: map a 0–100 score to a label bucket.
function chanceLabel(int $pct): string {
    if ($pct >= 85) return 'very_likely';
    if ($pct >= 60) return 'likely';
    if ($pct >= 30) return 'moderate';
    return 'low';
}

// Helper: pick the right qualifier word for inline use in messages.
function qualifier(int $pct): string {
    if ($pct >= 85) return 'very likely';
    if ($pct >= 60) return 'likely';
    return 'possible';
}

// Helper: decay a score over look-ahead days.
// On the event day itself we trust the engine score directly.
// Each day further away we step it down by ~15 pp (min 10).
function decayChance(int $baseScore, int $daysAway): int {
    return max(10, (int) round($baseScore * pow(0.82, $daysAway)));
}

$queryDate = new DateTime($dateParam);
$alerts = [];

// Collect event sets: current cycle anchor + all predictions.
$allEventSets = $predictions;

$currentOvDay  = (clone $lastStart)->modify('+' . ($ovulationDay - 1) . ' days');
$currentFStart = (clone $lastStart)->modify('+' . ($fertileStart - 1) . ' days');
$currentFEnd   = (clone $lastStart)->modify('+' . ($fertileEnd - 1) . ' days');
array_unshift($allEventSets, [
    'start'     => clone $lastStart,
    'end'       => (clone $lastStart)->modify('+' . (round($avgPeriod) - 1) . ' days'),
    'ovDay'     => $currentOvDay,
    'fertStart' => $currentFStart,
    'fertEnd'   => $currentFEnd,
    'margin'    => max(1, round($sdCycle)),
]);

foreach ($allEventSets as $ev) {

    // -----------------------------------------------------------------------
    // PERIOD alert
    // Base chance = profile's periodRisk (already Gaussian around period start,
    // 0–98). For look-ahead days we decay it since it's less certain further out.
    // -----------------------------------------------------------------------
    $periodStart     = $ev['start'];
    $daysUntilPeriod = (int)$queryDate->diff($periodStart)->days * ($periodStart < $queryDate ? -1 : 1);

    if ($daysUntilPeriod >= 0 && $daysUntilPeriod <= ALERT_PERIOD_DAYS_AHEAD) {
        // On the exact predicted start day, use the engine's periodRisk directly.
        // For future days, decay from a notional 95 (the score the engine would
        // give on day-0 of a perfectly regular cycle).
        $baseRisk    = ($daysUntilPeriod === 0) ? $profile['periodRisk'] : 95;
        $chance      = decayChance($baseRisk, $daysUntilPeriod);
        $label       = chanceLabel($chance);
        $qual        = qualifier($chance);

        if ($daysUntilPeriod === 0) {
            $level   = 'today';
            $message = "Possible menstrual period today ({$qual}, {$chance}%).";
        } elseif ($daysUntilPeriod === 1) {
            $level   = 'tomorrow';
            $message = "Your period may {$qual} start tomorrow ({$chance}%).";
        } else {
            $level   = 'upcoming';
            $message = "Your period is {$qual} to start in {$daysUntilPeriod} days ({$chance}%).";
        }
        $alerts[] = [
            'type'        => 'period',
            'level'       => $level,
            'daysUntil'   => $daysUntilPeriod,
            'date'        => $periodStart->format('Y-m-d'),
            'chance'      => $chance,
            'chanceLabel' => $label,
            'message'     => $message,
            'marginDays'  => $ev['margin'],
        ];
    }

    // -----------------------------------------------------------------------
    // OVULATION alert
    // Base chance = profile's fertilityScore on the predicted ovulation day.
    // -----------------------------------------------------------------------
    $ovDate      = $ev['ovDay'];
    $daysUntilOv = (int)$queryDate->diff($ovDate)->days * ($ovDate < $queryDate ? -1 : 1);

    if ($daysUntilOv >= 0 && $daysUntilOv <= ALERT_OVULATION_DAYS_AHEAD) {
        $baseOv  = ($daysUntilOv === 0) ? $profile['fertScore'] : 100;
        $chance  = decayChance($baseOv, $daysUntilOv);
        $label   = chanceLabel($chance);
        $qual    = qualifier($chance);

        if ($daysUntilOv === 0) {
            $level   = 'today';
            $message = "Possible ovulation today — {$qual} peak fertility ({$chance}%).";
        } elseif ($daysUntilOv === 1) {
            $level   = 'tomorrow';
            $message = "Ovulation is {$qual} tomorrow — fertility rising ({$chance}%).";
        } else {
            $level   = 'upcoming';
            $message = "Ovulation is {$qual} in {$daysUntilOv} days — fertile window opening ({$chance}%).";
        }
        $alerts[] = [
            'type'        => 'ovulation',
            'level'       => $level,
            'daysUntil'   => $daysUntilOv,
            'date'        => $ovDate->format('Y-m-d'),
            'chance'      => $chance,
            'chanceLabel' => $label,
            'message'     => $message,
        ];
    }

    // -----------------------------------------------------------------------
    // FERTILE WINDOW alert (opening day only)
    // Uses fertilityScore on that day; fertile window entry is inherently
    // lower certainty than ovulation itself, so we cap at 70.
    // -----------------------------------------------------------------------
    $fertWindowStart  = $ev['fertStart'];
    $daysUntilFert    = (int)$queryDate->diff($fertWindowStart)->days * ($fertWindowStart < $queryDate ? -1 : 1);

    if ($daysUntilFert === 0 || $daysUntilFert === 1) {
        $baseF   = ($daysUntilFert === 0) ? min(70, $profile['fertScore'] + 10) : 65;
        $chance  = decayChance($baseF, $daysUntilFert);
        $label   = chanceLabel($chance);
        $qual    = qualifier($chance);

        $level   = $daysUntilFert === 0 ? 'today' : 'tomorrow';
        $when    = $daysUntilFert === 0 ? 'opens today' : 'opens tomorrow';
        $message = "Your fertile window {$when} — {$qual} ({$chance}%).";

        $alerts[] = [
            'type'        => 'fertile_window',
            'level'       => $level,
            'daysUntil'   => $daysUntilFert,
            'date'        => $fertWindowStart->format('Y-m-d'),
            'chance'      => $chance,
            'chanceLabel' => $label,
            'message'     => $message,
        ];
    }
}

// De-duplicate (same type + date can appear if event sets overlap) and sort
// by daysUntil ascending so the most urgent alert comes first.
$seen = [];
$uniqueAlerts = [];
foreach ($alerts as $a) {
    $key = $a['type'] . '|' . $a['date'];
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $uniqueAlerts[] = $a;
    }
}
usort($uniqueAlerts, fn($a, $b) => $a['daysUntil'] <=> $b['daysUntil']);

// ==========================================
// 5. RESPOND
// ==========================================
http_response_code(200);
echo json_encode([
    'ok'   => true,
    'date' => $dateParam,
    'engine' => [
        'hasEnoughData'      => $hasEnoughData,
        'totalLoggedCycles'  => $totalLogs,
        'avgCycle'           => $avgCycle,
        'sdCycle'            => $sdCycle,
        'avgPeriod'          => $avgPeriod,
        'sdPeriod'           => $sdPeriod,
        'ovulationDay'       => $ovulationDay,
        'fertileWindow'      => [$fertileStart, $fertileEnd],
        'regularityScore'    => $regScore,
        'regularityLabel'    => $regLabel,
        'confidenceScore'    => $confScore,
    ],
    'profile' => [
        'cycleDay'         => $profile['cycleDay'],
        'totalDays'        => $profile['totalDays'],
        'phase'            => $profile['phase'],      // 'menstruation'|'follicular'|'ovulatory'|'luteal'
        'phaseLabel'       => $profile['phaseLabel'],
        'phaseColor'       => $profile['phaseColor'],
        'periodRisk'       => $profile['periodRisk'], // 0–98
        'fertilityScore'   => $profile['fertScore'],  // 0–100
        'cycleAnchorStart' => $profile['nearestStart']->format('Y-m-d'),
    ],
    // alerts — array, empty [] when nothing is imminent.
    // Each element:
    //   type:        "period" | "ovulation" | "fertile_window"
    //   level:       "today" | "tomorrow" | "upcoming"
    //   daysUntil:   int (0 = today)
    //   date:        "YYYY-MM-DD"
    //   chance:      int 0–100 — probabilistic likelihood for this specific date
    //   chanceLabel: "low" | "moderate" | "likely" | "very_likely"
    //   message:     human-readable string, phrased to match the chance level
    //   marginDays:  int (period alerts only) — ±uncertainty from cycle SD
    'alerts' => $uniqueAlerts,
], JSON_PRETTY_PRINT);