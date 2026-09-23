<?php
/**
 * Mocap dataset overview — what data exists, per capture session, and where it lives.
 * Numbers come from data/inventory.json, written by build_inventory.py.
 */

require __DIR__ . '/lib/auth.php';
requirePagePassword();

$inv = json_decode(@file_get_contents(__DIR__ . '/data/inventory.json'), true);
if (!$inv) {
    http_response_code(503);
    exit('Inventory not built yet: run python3 /web/mocapOverview/build_inventory.py');
}

$sessions = $inv['sessions'];
$items = [];
foreach ($inv['items'] as $it) {
    $items[$it['key']] = $it;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return $v ? number_format($v, 0, ',', '.') : ''; }
function sz($b) {
    if (!$b) return '';
    foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6, 'KB' => 1e3] as $u => $f) {
        if ($b >= $f) return round($b / $f, $b >= 10 * $f ? 0 : 1) . ' ' . $u;
    }
    return $b . ' B';
}
function cell($it, $s, $what = 'takes') { return $it['by_session'][$s][$what] ?? 0; }

$groups = [
    'raw'        => ['Raw capture data', 'Straight from the Vicon system and capture PC. Capture-PC data is indexed in <code>vicon_files</code> (use viconDashboard); a backup copy sits on the NAS share.'],
    'animation'  => ['Animation files', 'Solved and retargeted animation per take, on the server. CC = Character Creator avatar "Palmer" (used by animMIDI and the viewers); RPM = ReadyPlayerMe avatar "glassesGuy" (first pipeline).'],
    'annotation' => ['Annotation', 'ELAN files and subtitle tiers, edited in zinnen.html / 3DAnn.'],
    'video'      => ['Video', 'Reference and studio video. Broadcast-level videos count under a session only when that broadcast has a mocap take; the rest is under "No mocap".'],
    'csv'        => ['CSV & metadata', ''],
    'derived'    => ['Derived data', 'Computed from video or animation; useful, but not raw capture.'],
];

// Headline numbers per session (takes).
$headline = [
    'pc_unreal_cc'  => 'CC raw (capture PC)',
    'cc_raw'        => 'CC raw on server',
    'cc_pp'         => 'CC post-processed',
    'rpm_fbx'       => 'RPM raw',
    'rpm_pp_fbx'    => 'RPM post-processed',
    'eaf_take'      => 'Take-level EAF',
    'razer'         => 'Takes with OBS video',
    'bm_mini'       => 'Takes with Blackmagic',
];
$cardSessions = array_values(array_filter($sessions, fn($s) => $s['id'] !== 'nomocap'));

$totalBytes = 0;
foreach ($items as $it) {
    if ($it['group'] !== 'annotation' || $it['key'] !== 'eaf_bak') $totalBytes += $it['total']['bytes'];
}
// Backups are versions of the same file, not takes.
$items['eaf_bak']['total']['takes'] = 0;
foreach ($items['eaf_bak']['by_session'] as &$c) $c['takes'] = 0;
unset($c);

$pcTakes = $items['pc_x2d']['total']['takes'];
// The register also lists the Nov–Dec 2025 Zin takes that are on the capture PC; count those once.
$firstTakes = $items['db_mocapfiles']['total']['takes'] - cell($items['db_mocapfiles'], 'zin');

// Takes per session: the largest distinct-take count among the sources that list every take.
$takeSources = ['pc_x2d' => 'Vicon capture PC', 'db_mocapfiles' => 'first-pipeline register',
                'rpm_fbx' => 'RPM animation files', 'nas_3dlex_anim' => '3DLEX 2024 captures'];
function sessionTakes($items, $sources, $id) {
    $best = [0, ''];
    foreach ($sources as $k => $label) {
        if (isset($items[$k]) && cell($items[$k], $id) > $best[0]) $best = [cell($items[$k], $id), $label];
    }
    return $best;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mocap Dataset Overview</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #f6f5f1; --panel: #ffffff; --ink: #1c1d1f; --muted: #6b6d72; --line: #e2e0d9; --soft: #efede7;
  --accent: #2c5e8f; --zin: #2c5e8f; --bak: #b5562a; --3dlex: #4d7a3a; --lsc: #8a4d8f; --other: #7a7a7a; --nomocap: #a7a59d;
  --mono: 'IBM Plex Mono', ui-monospace, monospace; --sans: 'IBM Plex Sans', system-ui, sans-serif;
}
@media (prefers-color-scheme: dark) {
  :root { --bg: #151618; --panel: #1d1f22; --ink: #e8e7e3; --muted: #9a9ca1; --line: #303236; --soft: #25272b;
          --accent: #7fb0e0; --zin: #7fb0e0; --bak: #e59366; --3dlex: #93c47d; --lsc: #c894cc; --other: #a5a5a5; --nomocap: #6f6e69; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 var(--sans); }
a { color: var(--accent); }
code, .mono { font-family: var(--mono); font-size: 0.86em; }
.wrap { max-width: 1320px; margin: 0 auto; padding: 32px 16px 80px; }
header h1 { font-size: 28px; font-weight: 600; margin: 0 0 4px; letter-spacing: -0.01em; }
header p { color: var(--muted); margin: 0; max-width: 820px; }
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1px; background: var(--line);
        border: 1px solid var(--line); border-radius: 8px; overflow: hidden; margin: 24px 0 36px; }
.kpi { background: var(--panel); padding: 14px 16px; }
.kpi b { display: block; font-size: 24px; font-weight: 600; font-variant-numeric: tabular-nums; }
.kpi span { color: var(--muted); font-size: 13px; }
h2 { font-size: 19px; font-weight: 600; margin: 44px 0 6px; }
h2 + p.lead { color: var(--muted); margin: 0 0 14px; max-width: 900px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 14px; }
.card { background: var(--panel); border: 1px solid var(--line); border-top: 4px solid var(--c); border-radius: 8px; padding: 16px; }
.card h3 { margin: 0; font-size: 17px; }
.card .period { color: var(--muted); font-size: 13px; }
.card p { font-size: 13px; color: var(--muted); margin: 8px 0 12px; }
.card dl { display: grid; grid-template-columns: 1fr auto; gap: 3px 12px; margin: 0; font-size: 13.5px; }
.card dt { color: var(--muted); } .card dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; font-weight: 500; }
.card .big { font-size: 26px; font-weight: 600; font-variant-numeric: tabular-nums; margin: 4px 0 2px; }
.tablewrap { overflow-x: auto; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
table { border-collapse: collapse; width: 100%; min-width: 1100px; font-size: 13.5px; }
th, td { padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; text-align: left; }
thead th { position: sticky; top: 0; background: var(--soft); font-weight: 600; font-size: 12.5px; white-space: nowrap; }
th.num, td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
td.num small { display: block; color: var(--muted); font-size: 11.5px; }
td.zero { color: var(--line); }
tr.grp td { background: var(--soft); font-weight: 600; font-size: 13px; }
tr.grp td span { font-weight: 400; color: var(--muted); margin-left: 8px; }
.what b { font-weight: 500; } .what .note { color: var(--muted); font-size: 12.5px; margin-top: 2px; max-width: 380px; }
.where { max-width: 360px; } .where .path { font-family: var(--mono); font-size: 12px; word-break: break-all; }
.where .host { font-size: 11.5px; color: var(--muted); }
.tag { display: inline-block; font-size: 11px; padding: 0 6px; border-radius: 3px; background: var(--soft); color: var(--muted); margin-left: 4px; vertical-align: 1px; }
.tag.cc { background: color-mix(in srgb, var(--zin) 18%, transparent); color: var(--zin); }
.tag.rpm { background: color-mix(in srgb, var(--bak) 18%, transparent); color: var(--bak); }
.tag.vicon { background: color-mix(in srgb, var(--3dlex) 18%, transparent); color: var(--3dlex); }
.tag.pp { background: var(--ink); color: var(--panel); }
.sdot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: var(--c); margin-right: 6px; }
.toggle { display: inline-flex; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; margin: 4px 0 12px; }
.toggle button { font: inherit; font-size: 13px; border: 0; background: var(--panel); color: var(--ink); padding: 5px 12px; cursor: pointer; }
.toggle button.on { background: var(--ink); color: var(--panel); }
.links { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 12px; }
.links a.box { display: block; text-decoration: none; color: inherit; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; }
.links a.box:hover { border-color: var(--accent); }
.links b { color: var(--accent); } .links span { display: block; color: var(--muted); font-size: 13px; margin-top: 3px; }
.cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; }
.panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px 18px; font-size: 14px; }
.panel h3 { margin: 0 0 8px; font-size: 15px; }
.panel ul { margin: 0; padding-left: 18px; } .panel li { margin: 4px 0; }
.sub { display: flex; gap: 4px; margin: 0 0 20px; border-bottom: 1px solid var(--line); }
.sub a { padding: 8px 12px; text-decoration: none; color: var(--muted); font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.sub a.on { color: var(--ink); border-bottom-color: var(--ink); }
footer { margin-top: 48px; color: var(--muted); font-size: 12.5px; }
body.v-files .v-takes, body.v-takes .v-files, body.v-bytes .v-takes, body.v-bytes .v-files,
body.v-files .v-bytes, body.v-takes .v-bytes { display: none; }
@media (max-width: 600px) { .cols { grid-template-columns: 1fr; } header h1 { font-size: 23px; } }
</style>
</head>
<body class="v-takes">
<div class="wrap">

<nav class="sub"><a class="on" href="/mocapOverview/">Overview</a><a href="/mocapOverview/api/">API</a></nav>
<header>
  <h1>Mocap dataset overview</h1>
  <p>All motion-capture data of the signCollect studio: raw Vicon captures, animation files per avatar (raw and post-processed), annotation, video and CSV, split by capture session, with the location of every file type.</p>
</header>

<div class="kpis">
  <div class="kpi"><b><?= n($pcTakes) ?></b><span>Vicon takes on the capture PC (since Sep 2025)</span></div>
  <div class="kpi"><b><?= n($firstTakes) ?></b><span>First-pipeline takes, 2024–2025 (3DLEX, LSC, tests)</span></div>
  <div class="kpi"><b><?= n($items['cc_raw']['total']['takes']) ?></b><span>CC animations on the server</span></div>
  <div class="kpi"><b><?= n($items['cc_pp']['total']['takes'] + $items['rpm_pp_fbx']['total']['takes']) ?></b><span>Post-processed animations</span></div>
  <div class="kpi"><b><?= n($items['eaf_take']['total']['files'] + $items['eaf_bc']['total']['files']) ?></b><span>EAF annotation files</span></div>
  <div class="kpi"><b><?= sz($totalBytes) ?></b><span>Total volume listed below</span></div>
</div>

<h2>Capture sessions</h2>
<p class="lead">Every take is assigned to one session by its name. Counts are distinct takes.</p>
<div class="cards">
<?php foreach ($cardSessions as $s): $id = $s['id'];
    [$takes, $takesFrom] = sessionTakes($items, $takeSources, $id); ?>
  <div class="card" style="--c: var(--<?= h($id) ?>)">
    <h3><?= h($s['name']) ?></h3>
    <div class="period"><?= h($s['period']) ?></div>
    <div class="big"><?= n($takes) ?: '0' ?></div>
    <div class="period">takes recorded (per <?= h($takesFrom) ?>)</div>
    <p><?= h($s['desc']) ?></p>
    <dl>
    <?php foreach ($headline as $k => $label): $v = cell($items[$k], $id); if (!$v) continue; ?>
      <dt><?= h($label) ?></dt><dd><?= n($v) ?></dd>
    <?php endforeach; ?>
    </dl>
  </div>
<?php endforeach; ?>
</div>

<h2>Full inventory</h2>
<p class="lead">Rows are file types, columns are sessions. <em>Takes</em> counts distinct recordings (a take with FBX + GLB counts once); <em>files</em> and <em>size</em> count everything on disk.</p>
<div class="toggle" role="group" aria-label="Unit">
  <button data-v="takes" class="on">Takes</button><button data-v="files">Files</button><button data-v="bytes">Size</button>
</div>
<div class="tablewrap">
<table>
  <thead><tr>
    <th>Data</th>
    <?php foreach ($sessions as $s): ?><th class="num"><span class="sdot" style="--c: var(--<?= h($s['id']) ?>)"></span><?= h($s['name']) ?></th><?php endforeach; ?>
    <th class="num">Total</th><th>Where</th>
  </tr></thead>
  <tbody>
  <?php foreach ($groups as $g => [$gTitle, $gNote]): ?>
    <tr class="grp"><td colspan="<?= count($sessions) + 3 ?>"><?= h($gTitle) ?><span><?= $gNote ?></span></td></tr>
    <?php foreach ($items as $it): if ($it['group'] !== $g) continue; ?>
    <tr>
      <td class="what"><b><?= h($it['title']) ?></b>
        <span class="tag"><?= h($it['fmt']) ?></span>
        <?php if (!empty($it['avatar'])): ?><span class="tag <?= h($it['avatar']) ?>"><?= h(strtoupper($it['avatar'])) ?></span><?php endif; ?>
        <?php if (($it['stage'] ?? '') === 'pp'): ?><span class="tag pp">post-processed</span><?php endif; ?>
        <?php if ($it['note']): ?><div class="note"><?= h($it['note']) ?></div><?php endif; ?>
      </td>
      <?php foreach (array_merge(array_column($sessions, 'id'), ['__total']) as $sid):
          $c = $sid === '__total' ? $it['total'] : $it['by_session'][$sid];
          $empty = !$c['files']; ?>
      <td class="num<?= $empty ? ' zero' : '' ?>"><?php if ($empty): ?>–<?php else: ?>
        <span class="v-takes"><?= n($c['takes']) ?: '<small>' . n($c['files']) . ' files</small>' ?></span>
        <span class="v-files"><?= n($c['files']) ?></span>
        <span class="v-bytes"><?= sz($c['bytes']) ?: '—' ?></span>
      <?php endif; ?></td>
      <?php endforeach; ?>
      <td class="where"><div class="host"><?= h($it['host']) ?></div>
        <div class="path"><?= h($it['path']) ?></div>
        <?php if (!empty($it['url'])): ?><a class="mono" href="<?= h($it['url']) ?>"><?= h($it['url']) ?></a><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Where to work with the data</h2>
<p class="lead">Tools on signcollect.nl that read, edit or download these files.</p>
<div class="links">
  <a class="box" href="/mocapOverview/api/"><b>Mocap files API</b><span>Query animation files by gloss, Signbank ID, sentence ID, wildcard, date or date range and type; returns download URLs. Docs and playground.</span></a>
  <a class="box" href="/animMIDI/"><b>animMIDI</b><span>Download raw CC FBX per capture date, upload post-processed FBX, 3D preview/compare, batch download of EAF bundles (MCP Klaar).</span></a>
  <a class="box" href="https://signcollect.nl/viconDashboard/"><b>viconDashboard</b><span>Live index of the capture PC (<code>E:\Recordings</code>): takes, file sizes, Shogun/OBS/Unreal status.</span></a>
  <a class="box" href="/zin/zinnen.html"><b>Zinnen (zin)</b><span>Sentence list with videos, MCP status (postprocessing / gloss timing) and the annotation editor.</span></a>
  <a class="box" href="/zin/3DAnn1.html"><b>3DAnn</b><span>Annotate glosses against the 3D animation and the reference video; writes the EAF/SRT files.</span></a>
  <a class="box" href="/mocapStudio/3dOpname.html"><b>3dOpname (mocapStudio)</b><span>Recording front-end used in the studio: Glosses, HH, Sentences and BAK modes.</span></a>
  <a class="box" href="/studioIndex/"><b>studioIndex</b><span>Sign Language Archive: browse the studio recordings.</span></a>
</div>

<h2>Reading the file names</h2>
<div class="cols">
  <div class="panel">
    <h3>Names</h3>
    <ul>
      <li><code>M20260113_9959_260319_0.fbx</code> — reference video <code>M20260113_9959</code>, captured 2026-03-19, take 0 (Zin and BAK).</li>
      <li><code>M20260113_9959.mp4</code> — the reference video itself (<code>L</code>/<code>M</code>/<code>R</code> prefix = left/middle/right webcam).</li>
      <li><code>TAART-B_241127_0_GlassesGuyRecord_C_1.fbx</code> — Signbank gloss, captured 2024-11-27, take 0, recorded on the RPM avatar (3DLEX).</li>
      <li><code>…_LEFT_2026-09-18_14-46-37.mkv</code> — OBS webcam file for a take, with the wall-clock start time.</li>
      <li>Capture PC layout: <code>E:\Recordings\{date}\{take}\{shogun_live | shogun_post | obs | unreal | unreal\CC | unreal\Vicon | metadata}</code>.</li>
    </ul>
  </div>
  <div class="panel">
    <h3>Avatars &amp; stages</h3>
    <ul>
      <li><b>CC (Palmer)</b> — Character Creator rig (<code>cc_base_*</code>), the current production avatar. Animation GLBs are in metres, the avatar in centimetres.</li>
      <li><b>RPM (glassesGuy)</b> — ReadyPlayerMe rig (<code>Hips</code>, <code>LeftEye</code>), used from Nov 2024; still exported by Unreal for every take.</li>
      <li><b>Vicon</b> — plain Vicon skeleton, no retargeting.</li>
      <li><b>Raw</b> = as exported after capture. <b>Post-processed</b> = cleaned by an engineer in Unreal and uploaded via animMIDI.</li>
      <li><b>MCP Klaar</b> = sentence has postprocessing = Klaar and gloss timing = Klaar (zinnen.html).</li>
    </ul>
  </div>
  <div class="panel">
    <h3>How sessions are assigned</h3>
    <ul>
      <li>Reference-video names go through <code>matched_transcriptions</code>: type <code>zin</code> → Zin in NGT; types <code>labels</code>/<code>extern</code> with label “Basiswoordenlijst Amsterdamse Kleuters” → BAK.</li>
      <li>Gloss names: NGT Signbank glosses (upper case, <code>mocap_data</code>) → 3DLEX; Catalan glosses (<code>csl_glosses</code>) and other names recorded 3–19 Feb 2025 → LSC/LSE.</li>
      <li>Names starting with <code>test</code>, pilots and names without a transcription → Tests &amp; pilots.</li>
    </ul>
  </div>
</div>

<footer>
  Generated <?= h($inv['generated']) ?> by <code>/web/mocapOverview/build_inventory.py</code> (<?= round($inv['build_seconds'] / 60) ?> min).
  Capture-PC numbers come from the <code>vicon_files</code> index; everything else is counted on disk. · <a href="?logout=1">Log out</a>
</footer>
</div>
<script>
document.querySelectorAll('.toggle button').forEach(function (b) {
  b.addEventListener('click', function () {
    document.querySelectorAll('.toggle button').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.body.className = 'v-' + b.dataset.v;
  });
});
</script>
</body>
</html>
