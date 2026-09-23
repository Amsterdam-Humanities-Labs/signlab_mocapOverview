<?php
/**
 * GET /mocapOverview/api/files — search animation files.
 * GET /mocapOverview/api/meta  — types, sessions, date range and counts (files.php?meta=1).
 *
 * Reads the mocapoverview_files table built by ../build_inventory.py. Parameters are
 * documented on /mocapOverview/api/ (index.php) — keep both in sync.
 */

require __DIR__ . '/../lib/auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

requireApiAccess();

const TYPES = [
    'cc_raw'    => 'CC avatar (Palmer), raw — fbx/CC/',
    'cc_pp'     => 'CC avatar (Palmer), post-processed — fbx/post_processed/',
    'ccp_raw'   => 'CC + face shapekeys, raw — fbx/cc_pipeline/ (_anim.glb + _shapekeys.json)',
    'ccp_pp'    => 'CC + face shapekeys, post-processed — fbx/cc_pipeline_pp/',
    'rpm_raw'   => 'ReadyPlayerMe avatar (glassesGuy), raw — fbx/',
    'rpm_pp'    => 'ReadyPlayerMe avatar, post-processed — fbx/post_processed/',
    'vicon_raw' => 'Vicon skeleton, raw — fbx/Vicon/ and fbx/',
];
const SESSIONS = ['zin', 'bak', '3dlex', 'lsc', 'other'];
const FIELDS = 'file, take, name, session, type, avatar, stage, format, capture_date, take_no, gloss, signbank_id, '
             . 'record_id, sentence, TRIM(glosses) AS glosses, size_bytes, modified, url';

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $code === 400 ? 'bad_request' : 'error', 'message' => $msg]);
    exit;
}

/** Comma-separated parameter -> trimmed non-empty values. */
function listParam(string $name): array {
    $v = $_GET[$name] ?? '';
    if (is_array($v)) $v = implode(',', $v);
    return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
}

/** User wildcard (* and ?) -> SQL LIKE pattern with LIKE metacharacters escaped. */
function likePattern(string $v): string {
    $v = addcslashes($v, '\\%_');
    return strtr($v, ['*' => '%', '?' => '_']);
}

/** YYYY, YYYY-MM or YYYY-MM-DD -> [first day, last day]. */
function dateBounds(string $v, string $param): array {
    if (preg_match('/^(\d{4})$/', $v)) return ["$v-01-01", "$v-12-31"];
    if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], 1, (int)$m[1])) {
        return ["$v-01", date('Y-m-t', strtotime("$v-01"))];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return [$v, $v];
    fail(400, "$param must be YYYY, YYYY-MM or YYYY-MM-DD, got '$v'");
}

$config = (function () {
    include '/web/mysql_config.php';
    return [$servername, $username, $password, $database];
})();
$pdo = new PDO("mysql:host={$config[0]};dbname={$config[3]};charset=utf8mb4", $config[1], $config[2],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ---------------------------------------------------------------- meta
if (isset($_GET['meta'])) {
    $rows = $pdo->query("SELECT type, session, COUNT(*) files, COUNT(DISTINCT take) takes,
                                MIN(capture_date) first_date, MAX(capture_date) last_date
                         FROM mocapoverview_files GROUP BY type, session ORDER BY type, session")->fetchAll(PDO::FETCH_ASSOC);
    $built = $pdo->query("SELECT UPDATE_TIME, CREATE_TIME FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mocapoverview_files'")->fetch(PDO::FETCH_NUM);
    header('Content-Type: application/json');
    echo json_encode(['index_built' => $built[0] ?? $built[1], 'types' => TYPES, 'sessions' => SESSIONS,
                      'formats' => ['fbx', 'glb', 'shapekeys'], 'counts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------- filters
$where = [];
$args = [];

/** Adds "(col LIKE ? OR col LIKE ? ...)" for a list of wildcard values. */
$likeAny = function (array $cols, array $values) use (&$where, &$args) {
    $or = [];
    foreach ($values as $v) {
        foreach ($cols as $c) {
            $or[] = "$c LIKE ?";
            $args[] = likePattern($v);
        }
    }
    $where[] = '(' . implode(' OR ', $or) . ')';
};
$intIn = function (string $col, array $values, string $param) use (&$where, &$args) {
    foreach ($values as $v) {
        if (!ctype_digit($v)) fail(400, "$param must be whole numbers, got '$v'");
    }
    $where[] = "$col IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
    array_push($args, ...array_map('intval', $values));
};
$enumIn = function (string $col, array $values, array $allowed, string $param) use (&$where, &$args) {
    foreach ($values as $v) {
        if (!in_array($v, $allowed, true)) fail(400, "$param must be one of " . implode(', ', $allowed) . ", got '$v'");
    }
    $where[] = "$col IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
    array_push($args, ...$values);
};

if ($v = listParam('gloss'))          $likeAny(['gloss'], $v);
if ($v = listParam('name'))           $likeAny(['name'], $v);
if ($v = listParam('take'))           $likeAny(['take'], $v);
if ($v = listParam('file'))           $likeAny(['file'], $v);
if ($v = listParam('sentence_gloss')) $likeAny(['glosses'], array_map(fn($g) => "* $g *", $v));
if ($v = listParam('signbank_id'))    $intIn('signbank_id', $v, 'signbank_id');
if ($v = listParam('id'))             $intIn('record_id', $v, 'id');
if ($v = listParam('type'))           $enumIn('type', $v, array_keys(TYPES), 'type');
if ($v = listParam('session'))        $enumIn('session', $v, SESSIONS, 'session');
if ($v = listParam('avatar'))         $enumIn('avatar', $v, ['cc', 'rpm', 'vicon'], 'avatar');
if ($v = listParam('stage'))          $enumIn('stage', $v, ['raw', 'pp'], 'stage');
if ($v = listParam('format'))         $enumIn('format', $v, ['fbx', 'glb', 'shapekeys'], 'format');
if (($q = trim($_GET['q'] ?? '')) !== '') {
    $likeAny(['gloss', 'name', 'take', 'sentence', 'glosses'], ['*' . $q . '*']);
}
if (($d = trim($_GET['date'] ?? '')) !== '') {
    $where[] = 'capture_date BETWEEN ? AND ?';
    array_push($args, ...dateBounds($d, 'date'));
}
if (($d = trim($_GET['date_from'] ?? '')) !== '') {
    $where[] = 'capture_date >= ?';
    $args[] = dateBounds($d, 'date_from')[0];
}
if (($d = trim($_GET['date_to'] ?? '')) !== '') {
    $where[] = 'capture_date <= ?';
    $args[] = dateBounds($d, 'date_to')[1];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// latest=1: per name, type and format keep only the most recent take (latest capture date, highest take number).
$from = 'mocapoverview_files';
if (!empty($_GET['latest']) && $_GET['latest'] !== '0') {
    $from = "(SELECT *, ROW_NUMBER() OVER (PARTITION BY name, type, format
                                           ORDER BY capture_date DESC, take_no DESC) AS rn
              FROM mocapoverview_files $whereSql) f";
    $whereSql = 'WHERE rn = 1';
}

$sorts = ['name' => 'name, capture_date, take_no', 'date' => 'capture_date, name, take_no',
          '-date' => 'capture_date DESC, name, take_no DESC', 'size' => 'size_bytes', '-size' => 'size_bytes DESC',
          'modified' => 'modified', '-modified' => 'modified DESC'];
$sort = $_GET['sort'] ?? 'name';
if (!isset($sorts[$sort])) fail(400, 'sort must be one of ' . implode(', ', array_keys($sorts)));

$out = $_GET['output'] ?? 'json';
if (!in_array($out, ['json', 'csv', 'urls'], true)) fail(400, 'output must be json, csv or urls');
$maxLimit = $out === 'json' ? 1000 : 50000;
$limit = (int)($_GET['limit'] ?? ($out === 'json' ? 100 : $maxLimit));
if ($limit < 1 || $limit > $maxLimit) fail(400, "limit must be 1–$maxLimit for output=$out");
$offset = max(0, (int)($_GET['offset'] ?? 0));
$group = $_GET['group'] ?? '';
if (!in_array($group, ['', 'take'], true)) fail(400, "group must be 'take' or empty");
if ($group && $out !== 'json') fail(400, 'group=take only works with output=json');

// ---------------------------------------------------------------- query
$count = $pdo->prepare("SELECT COUNT(*), COUNT(DISTINCT take), COALESCE(SUM(size_bytes), 0) FROM $from $whereSql");
$count->execute($args);
[$total, $totalTakes, $totalBytes] = array_map('intval', $count->fetch(PDO::FETCH_NUM));

if ($group === 'take') {
    // Page over takes, then fetch all their files.
    $takeSql = "SELECT take FROM $from $whereSql GROUP BY take
                ORDER BY " . (['name' => 'MIN(name), take', 'date' => 'MIN(capture_date), take',
                                  '-date' => 'MIN(capture_date) DESC, take'][$sort] ?? 'take') . " LIMIT $limit OFFSET $offset";
    $st = $pdo->prepare($takeSql);
    $st->execute($args);
    $takes = $st->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    if ($takes) {
        $in = implode(',', array_fill(0, count($takes), '?'));
        $cond = $whereSql ? "$whereSql AND" : 'WHERE';
        $st = $pdo->prepare("SELECT " . FIELDS . " FROM $from $cond take IN ($in) ORDER BY take, type, format");
        $st->execute(array_merge($args, $takes));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $st = $pdo->prepare("SELECT " . FIELDS . " FROM $from $whereSql ORDER BY {$sorts[$sort]} LIMIT $limit OFFSET $offset");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($rows as &$r) {
    foreach (['take_no', 'signbank_id', 'record_id', 'size_bytes'] as $k) {
        if ($r[$k] !== null) $r[$k] = (int)$r[$k];
    }
}
unset($r);

// ---------------------------------------------------------------- output
if ($out === 'urls') {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", array_column($rows, 'url')), "\n";
    exit;
}
if ($out === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mocap-files.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, $rows ? array_keys($rows[0]) : explode(', ', str_replace('TRIM(glosses) AS ', '', FIELDS)));
    foreach ($rows as $r) fputcsv($fh, $r);
    exit;
}

$result = ['total_files' => $total, 'total_takes' => $totalTakes, 'total_bytes' => $totalBytes,
           'limit' => $limit, 'offset' => $offset];
if ($group === 'take') {
    $byTake = [];
    foreach ($rows as $r) {
        $t = $r['take'];
        if (!isset($byTake[$t])) {
            $byTake[$t] = ['take' => $t] + array_intersect_key($r, array_flip(
                ['name', 'session', 'capture_date', 'take_no', 'gloss', 'signbank_id', 'record_id', 'sentence', 'glosses'])) + ['files' => []];
        }
        $byTake[$t]['files'][] = array_intersect_key($r, array_flip(['file', 'type', 'avatar', 'stage', 'format', 'size_bytes', 'modified', 'url']));
    }
    $result['count'] = count($byTake);
    $result['takes'] = array_values($byTake);
    $more = $offset + $limit < $totalTakes;
} else {
    $result['count'] = count($rows);
    $result['files'] = $rows;
    $more = $offset + $limit < $total;
}
if ($more) {
    $next = $_GET;
    unset($next['key']);
    $next['offset'] = $offset + $limit;
    $result['next'] = 'https://signcollect.nl/mocapOverview/api/files?' . http_build_query($next);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
