<?php
/**
 * API documentation and playground for /mocapOverview/api/files.
 * Keep the parameter table in sync with files.php.
 */
require __DIR__ . '/../lib/auth.php';
requirePagePassword();

$keys = apiKeys();
$key = $keys[0]['key'] ?? '';
$base = 'https://signcollect.nl/mocapOverview/api';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$params = [
    // name, example, description
    ['Search', null, null],
    ['gloss', 'TAART*', 'The sign’s gloss: Signbank gloss for 3DLEX, Catalan gloss for LSC, word-list gloss for BAK. Zin rows have no gloss (see <code>sentence_gloss</code>).'],
    ['signbank_id', '1662', 'Signbank gloss ID. 3DLEX uses NGT Signbank IDs, LSC uses Catalan IDs; the two ranges overlap, so combine with <code>session</code>.'],
    ['id', '6436', 'signCollect record ID: <code>sentences.ID</code> for Zin, <code>form_data.id</code> for BAK.'],
    ['sentence_gloss', 'GLIJBAAN', 'Zin sentences whose gloss list contains this gloss (whole gloss; wildcards allowed).'],
    ['name', 'M20260119_1147', 'Gloss (gloss takes) or reference video (Zin/BAK takes).'],
    ['take', 'M20260119_1147_260319_*', 'Take name: <code>{name}_{YYMMDD}_{take}</code>.'],
    ['file', '*_shapekeys.json', 'File name.'],
    ['q', 'glijbaan', 'Free text: substring of gloss, name, take, Dutch sentence or sentence glosses.'],
    ['Filter', null, null],
    ['type', 'cc_raw,cc_pp', 'File type, see the table below.'],
    ['session', 'zin', '<code>zin</code>, <code>bak</code>, <code>3dlex</code>, <code>lsc</code>, <code>other</code>.'],
    ['avatar', 'cc', '<code>cc</code> (Palmer), <code>rpm</code> (ReadyPlayerMe glassesGuy), <code>vicon</code>.'],
    ['stage', 'pp', '<code>raw</code> or <code>pp</code> (post-processed).'],
    ['format', 'fbx', '<code>fbx</code>, <code>glb</code> or <code>shapekeys</code> (face curves JSON).'],
    ['date', '2026-03', 'Capture date: a day <code>2026-03-19</code>, a month <code>2026-03</code> or a year <code>2026</code>.'],
    ['date_from', '2026-01-01', 'Capture date on or after (day, month or year).'],
    ['date_to', '2026-06', 'Capture date on or before (day, month or year; a month includes its last day).'],
    ['latest', '1', 'Keep only the most recent take per name, type and format (retakes dropped).'],
    ['Result', null, null],
    ['group', 'take', 'Group files per take: one entry per take with a <code>files</code> list. JSON only; <code>limit</code> then counts takes.'],
    ['sort', '-date', '<code>name</code> (default), <code>date</code>, <code>-date</code>, <code>size</code>, <code>-size</code>, <code>modified</code>, <code>-modified</code>.'],
    ['limit', '100', 'Page size. JSON: 1–1000 (default 100). CSV / urls: up to 50000 (default 50000).'],
    ['offset', '100', 'Skip this many results; the JSON <code>next</code> field holds the next page URL.'],
    ['output', 'json', '<code>json</code> (default), <code>csv</code> (download) or <code>urls</code> (plain list, one URL per line).'],
];
$types = [
    ['cc_raw', 'CC', 'raw', 'fbx/CC/', 'Character Creator avatar “Palmer”, as exported from Unreal. FBX + GLB.'],
    ['cc_pp', 'CC', 'post-processed', 'fbx/post_processed/', 'Cleaned in Unreal and uploaded through animMIDI. FBX + GLB.'],
    ['ccp_raw', 'CC', 'raw', 'fbx/cc_pipeline/', 'Web-viewer version: <code>_anim.glb</code> body + <code>_shapekeys.json</code> face curves.'],
    ['ccp_pp', 'CC', 'post-processed', 'fbx/cc_pipeline_pp/', 'Same, built from the post-processed FBX.'],
    ['rpm_raw', 'RPM', 'raw', 'fbx/', 'ReadyPlayerMe “glassesGuy”; first pipeline and still exported for every take. GLB includes the mesh.'],
    ['rpm_pp', 'RPM', 'post-processed', 'fbx/post_processed/', 'First-pipeline takes (3DLEX) cleaned in Unreal.'],
    ['vicon_raw', 'Vicon', 'raw', 'fbx/Vicon/, fbx/', 'Plain Vicon skeleton, no retargeting.'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mocap Files API</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #f6f5f1; --panel: #ffffff; --ink: #1c1d1f; --muted: #6b6d72; --line: #e2e0d9; --soft: #efede7;
  --accent: #2c5e8f; --ok: #3b7a3a; --err: #b3261e; --code-bg: #1f2124; --code-ink: #e6e4df;
  --mono: 'IBM Plex Mono', ui-monospace, monospace; --sans: 'IBM Plex Sans', system-ui, sans-serif;
}
@media (prefers-color-scheme: dark) {
  :root { --bg: #151618; --panel: #1d1f22; --ink: #e8e7e3; --muted: #9a9ca1; --line: #303236; --soft: #25272b;
          --accent: #7fb0e0; --ok: #93c47d; --err: #f2b8b5; --code-bg: #111214; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.55 var(--sans); }
a { color: var(--accent); }
code { font-family: var(--mono); font-size: 0.86em; background: var(--soft); padding: 1px 4px; border-radius: 3px; }
pre { font-family: var(--mono); font-size: 12.5px; background: var(--code-bg); color: var(--code-ink); padding: 14px 16px;
      border-radius: 8px; overflow-x: auto; margin: 8px 0 16px; line-height: 1.5; }
pre code { background: none; padding: 0; font-size: inherit; color: inherit; }
.wrap { max-width: 1180px; margin: 0 auto; padding: 32px 16px 80px; }
.sub { display: flex; gap: 4px; margin: 0 0 20px; border-bottom: 1px solid var(--line); }
.sub a { padding: 8px 12px; text-decoration: none; color: var(--muted); font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.sub a.on { color: var(--ink); border-bottom-color: var(--ink); }
h1 { font-size: 28px; font-weight: 600; margin: 0 0 4px; letter-spacing: -0.01em; }
.lead { color: var(--muted); margin: 0 0 8px; max-width: 800px; }
h2 { font-size: 19px; font-weight: 600; margin: 44px 0 8px; }
h3 { font-size: 15px; font-weight: 600; margin: 22px 0 4px; }
.toc { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 16px 0 0; font-size: 14px; }
.panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 16px 18px; }
table { border-collapse: collapse; width: 100%; font-size: 14px; }
th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
thead th { background: var(--soft); font-size: 12.5px; }
tr.sec td { background: var(--soft); font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
td.p { font-family: var(--mono); font-size: 13px; white-space: nowrap; font-weight: 500; }
td.ex { font-family: var(--mono); font-size: 12.5px; color: var(--muted); white-space: nowrap; }
.tablewrap { overflow-x: auto; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; }
.keybox { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.keybox code { font-size: 13px; padding: 6px 8px; }
button, .btn { font: inherit; font-size: 14px; border: 1px solid var(--line); background: var(--panel); color: var(--ink);
               padding: 6px 12px; border-radius: 6px; cursor: pointer; }
button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
button:disabled { opacity: .6; cursor: wait; }
/* playground */
.pg { display: grid; grid-template-columns: 340px 1fr; gap: 16px; align-items: start; }
.pg form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 10px; }
.pg label { display: block; font-size: 12.5px; color: var(--muted); font-weight: 500; }
.pg label.w { grid-column: 1 / -1; }
.pg input::placeholder { color: var(--muted); opacity: .55; }
.pg input, .pg select { width: 100%; font: inherit; font-size: 14px; padding: 6px 8px; border: 1px solid var(--line);
                        border-radius: 6px; background: var(--bg); color: var(--ink); margin-top: 2px; }
.pg .row { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
.presets { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
.presets button { font-size: 12.5px; padding: 3px 9px; }
.urlbar { font-family: var(--mono); font-size: 12.5px; background: var(--soft); border-radius: 6px; padding: 8px 10px; word-break: break-all; }
.status { font-size: 13px; color: var(--muted); margin: 10px 0 6px; } .status.err { color: var(--err); } .status.ok { color: var(--ok); }
.res { max-height: 520px; overflow: auto; border: 1px solid var(--line); border-radius: 6px; }
.res table { font-size: 12.5px; } .res td, .res th { padding: 5px 8px; white-space: nowrap; }
.res th { position: sticky; top: 0; background: var(--soft); }
.tabs { display: flex; gap: 4px; margin-top: 8px; }
.tabs button.on { background: var(--ink); color: var(--panel); border-color: var(--ink); }
.muted { color: var(--muted); font-size: 13px; }
@media (max-width: 900px) { .pg { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
<nav class="sub"><a href="/mocapOverview/">Overview</a><a class="on" href="/mocapOverview/api/">API</a></nav>

<h1>Mocap files API</h1>
<p class="lead">Find animation files (FBX, GLB, face shapekeys) by gloss, Signbank ID, sentence ID, wildcard, capture date or date range, file type, avatar and session. Every result carries a direct download URL.</p>
<div class="toc"><a href="#start">Quick start</a><a href="#auth">Authentication</a><a href="#files">GET /files</a><a href="#params">Parameters</a><a href="#types">File types</a><a href="#response">Response</a><a href="#examples">Examples</a><a href="#meta">GET /meta</a><a href="#playground">Playground</a></div>

<h2 id="start">Quick start</h2>
<pre><code>curl -H "X-API-Key: <?= h($key) ?>" \
  "<?= $base ?>/files?gloss=TAART*&amp;type=cc_pp"</code></pre>

<h2 id="auth">Authentication</h2>
<div class="panel">
  <p style="margin-top:0">Send the API key in the <code>X-API-Key</code> header (preferred) or as <code>?key=</code> in the URL. Requests without a valid key get <code>401</code>. While you are logged in to this page, the playground below works without a key.</p>
  <div class="keybox"><span class="muted">Your key</span><code id="apikey"><?= h($key) ?></code><button type="button" data-copy="#apikey">Copy</button></div>
  <p class="muted" style="margin-bottom:0">Keys are stored in <code>/web/mocapOverview/data/api_keys.json</code>; add an entry there to give a project its own key.</p>
</div>

<h2 id="files">GET /files</h2>
<p><code><?= $base ?>/files</code> — search the animation file index. All parameters are optional and combine with AND; a comma-separated list inside one parameter means OR (<code>type=cc_raw,cc_pp</code>). Text parameters are case-insensitive and accept wildcards: <code>*</code> = any characters, <code>?</code> = one character. Without a wildcard the value must match exactly.</p>

<h3 id="params">Parameters</h3>
<div class="tablewrap"><table>
  <thead><tr><th>Parameter</th><th>Example</th><th>Meaning</th></tr></thead>
  <tbody>
  <?php foreach ($params as [$p, $ex, $desc]): ?>
    <?php if ($ex === null): ?><tr class="sec"><td colspan="3"><?= h($p) ?></td></tr>
    <?php else: ?><tr><td class="p"><?= h($p) ?></td><td class="ex"><?= h($ex) ?></td><td><?= $desc ?></td></tr><?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table></div>

<h3 id="types">File types</h3>
<div class="tablewrap"><table>
  <thead><tr><th>type</th><th>Avatar</th><th>Stage</th><th>Folder (under <code>/gebarenoverleg_media/</code>)</th><th>What it is</th></tr></thead>
  <tbody>
  <?php foreach ($types as [$t, $av, $st, $dir, $desc]): ?>
    <tr><td class="p"><?= h($t) ?></td><td><?= h($av) ?></td><td><?= h($st) ?></td><td><code><?= h($dir) ?></code></td><td><?= $desc ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="muted">Animation GLBs are in metres; the Palmer avatar is in centimetres (the viewers scale positions ×100). Only animation files on the web server are indexed; raw Vicon data (X2D/MCP) stays on the capture PC — see the <a href="/mocapOverview/">overview</a>.</p>

<h3 id="response">Response</h3>
<p class="muted">Response of <code>/files?gloss=TAART*&amp;format=glb</code> (shortened):</p>
<pre><code>{
  "total_files": 13,           // all matches, not just this page
  "total_takes": 4,
  "total_bytes": 48247184,
  "limit": 100, "offset": 0, "count": 13,
  "files": [
    {
      "file": "TAART-B_241127_0_GlassesGuyRecord_C_1.glb",
      "take": "TAART-B_241127_0",
      "name": "TAART-B",              // gloss, or reference video for Zin/BAK
      "session": "3dlex",             // zin | bak | 3dlex | lsc | other
      "type": "rpm_raw", "avatar": "rpm", "stage": "raw", "format": "glb",
      "capture_date": "2024-11-27", "take_no": 0,
      "gloss": "TAART-B",
      "signbank_id": 1662,
      "record_id": null,              // sentences.ID (Zin) or form_data.id (BAK)
      "sentence": null,               // Dutch sentence (Zin)
      "glosses": null,                // gloss sequence of the sentence (Zin)
      "size_bytes": 7669440,
      "modified": "2024-11-27 13:47:14",
      "url": "https://signcollect.nl/gebarenoverleg_media/fbx/TAART-B_241127_0_GlassesGuyRecord_C_1.glb"
    }
  ],
  "next": "…/files?gloss=TAART*&amp;offset=100"   // only when there is another page
}</code></pre>
<p class="muted">With <code>group=take</code> the list is called <code>takes</code>; each take has the take fields plus a <code>files</code> array. Errors return <code>400</code> with <code>{"error": "bad_request", "message": "…"}</code>.</p>

<h2 id="examples">Examples</h2>
<h3>A gloss, post-processed CC animation only</h3>
<pre><code>curl -H "X-API-Key: $KEY" "<?= $base ?>/files?gloss=HUT&amp;type=cc_pp"</code></pre>
<h3>All glosses starting with TAART, GLB only</h3>
<pre><code>curl -H "X-API-Key: $KEY" "<?= $base ?>/files?gloss=TAART*&amp;format=glb"</code></pre>
<h3>By Signbank ID (3DLEX) or by sentence ID (Zin)</h3>
<pre><code>curl -H "X-API-Key: $KEY" "<?= $base ?>/files?signbank_id=1662&amp;session=3dlex"
curl -H "X-API-Key: $KEY" "<?= $base ?>/files?id=6436&amp;group=take"</code></pre>
<h3>Everything post-processed in March 2026, latest take only</h3>
<pre><code>curl -H "X-API-Key: $KEY" "<?= $base ?>/files?stage=pp&amp;date=2026-03&amp;latest=1"</code></pre>
<h3>A wide date range, BAK, CC raw FBX — download them all</h3>
<pre><code>curl -H "X-API-Key: $KEY" \
  "<?= $base ?>/files?session=bak&amp;type=cc_raw&amp;format=fbx&amp;date_from=2026-05-01&amp;date_to=2026-09-30&amp;output=urls" \
  | wget -i - -P bak_fbx/</code></pre>
<h3>Python: page through all results</h3>
<pre><code>import requests

KEY = "<?= h($key) ?>"
url = "<?= $base ?>/files"
params = {"session": "zin", "type": "cc_pp", "format": "fbx", "limit": 1000}
while url:
    r = requests.get(url, params=params, headers={"X-API-Key": KEY}).json()
    for f in r["files"]:
        print(f["take"], f["sentence"], f["url"])
    url, params = r.get("next"), None   # "next" already contains the query</code></pre>
<h3>JavaScript (browser or Node 18+)</h3>
<pre><code>const res = await fetch("<?= $base ?>/files?sentence_gloss=GLIJBAAN&amp;type=cc_pp&amp;format=glb",
                        { headers: { "X-API-Key": KEY } });
const { files } = await res.json();</code></pre>

<h2 id="meta">GET /meta</h2>
<p><code><?= $base ?>/meta</code> — the list of types and sessions, file and take counts per type and session, first/last capture date, and when the index was last rebuilt.</p>
<p class="muted">The index is rebuilt by <code>python3 /web/mocapOverview/build_inventory.py --files-only</code> (about 5 minutes); files added after the last rebuild are not found yet.</p>

<h2 id="playground">Playground</h2>
<p class="lead">Build a request, run it, and copy the URL or curl command. Runs with your login session.</p>
<div class="presets" id="presets">
  <span class="muted" style="align-self:center">Try:</span>
  <button type="button" data-q="gloss=TAART*&amp;format=glb">Wildcard gloss</button>
  <button type="button" data-q="signbank_id=1662&amp;session=3dlex">Signbank ID</button>
  <button type="button" data-q="id=6436&amp;group=take">Sentence ID, per take</button>
  <button type="button" data-q="sentence_gloss=GLIJBAAN&amp;type=cc_pp">Sentences with a gloss</button>
  <button type="button" data-q="stage=pp&amp;date=2026-03&amp;latest=1">Post-processed, March 2026</button>
  <button type="button" data-q="session=bak&amp;type=cc_raw&amp;format=fbx&amp;date_from=2026-05-01&amp;date_to=2026-09-30&amp;sort=-date">BAK date range</button>
</div>
<div class="pg">
  <div class="panel">
    <form id="pgform" autocomplete="off">
      <label class="w">gloss<input name="gloss" placeholder="TAART*  or  HUT,FIJN"></label>
      <label>signbank_id<input name="signbank_id" placeholder="1662"></label>
      <label>id (sentence / record)<input name="id" placeholder="6436"></label>
      <label class="w">sentence_gloss<input name="sentence_gloss" placeholder="GLIJBAAN"></label>
      <label>name<input name="name" placeholder="M20260119_1147"></label>
      <label>take<input name="take" placeholder="*_260319_*"></label>
      <label class="w">q (free text)<input name="q" placeholder="glijbaan"></label>
      <label>type<select name="type"><option value="">any</option>
        <?php foreach ($types as [$t]): ?><option><?= h($t) ?></option><?php endforeach; ?></select></label>
      <label>session<select name="session"><option value="">any</option>
        <option>zin</option><option>bak</option><option>3dlex</option><option>lsc</option><option>other</option></select></label>
      <label>avatar<select name="avatar"><option value="">any</option><option>cc</option><option>rpm</option><option>vicon</option></select></label>
      <label>stage<select name="stage"><option value="">any</option><option value="raw">raw</option><option value="pp">post-processed</option></select></label>
      <label>format<select name="format"><option value="">any</option><option>fbx</option><option>glb</option><option>shapekeys</option></select></label>
      <label>date<input name="date" placeholder="2026-03"></label>
      <label>date_from<input name="date_from" type="date"></label>
      <label>date_to<input name="date_to" type="date"></label>
      <label>latest<select name="latest"><option value="">all takes</option><option value="1">latest take only</option></select></label>
      <label>group<select name="group"><option value="">per file</option><option value="take">per take</option></select></label>
      <label>sort<select name="sort"><option value="">name</option><option>date</option><option>-date</option><option>size</option><option>-size</option><option>-modified</option></select></label>
      <label>limit<input name="limit" type="number" min="1" max="1000" placeholder="100"></label>
      <label>output<select name="output"><option value="">json</option><option>csv</option><option>urls</option></select></label>
      <div class="row">
        <button type="submit" class="primary" id="run">Run</button>
        <button type="button" id="clear">Clear</button>
      </div>
    </form>
  </div>
  <div>
    <div class="urlbar" id="url"></div>
    <div class="row" style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap">
      <button type="button" data-copy="#url">Copy URL</button>
      <button type="button" id="copycurl">Copy curl</button>
      <a class="btn" id="open" href="#" target="_blank" rel="noopener">Open in new tab</a>
    </div>
    <div class="status" id="status">Choose parameters or a preset, then Run.</div>
    <div class="tabs" id="tabs" hidden><button type="button" data-t="table" class="on">Table</button><button type="button" data-t="raw">Raw</button></div>
    <div class="res" id="res" hidden></div>
  </div>
</div>

</div>
<script>
(function () {
  var BASE = <?= json_encode($base) ?>;
  var KEY = <?= json_encode($key) ?>;
  var form = document.getElementById('pgform');
  var last = null, view = 'table';

  function query() {
    var p = new URLSearchParams();
    new FormData(form).forEach(function (v, k) { v = String(v).trim(); if (v) p.append(k, v); });
    return p.toString();
  }
  function url() { var q = query(); return BASE + '/files' + (q ? '?' + q : ''); }
  function refresh() {
    document.getElementById('url').textContent = url();
    document.getElementById('open').href = url();
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function size(b) { return b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : b >= 1e6 ? (b / 1e6).toFixed(1) + ' MB' : Math.round(b / 1e3) + ' KB'; }

  function render() {
    var res = document.getElementById('res');
    res.hidden = false;
    if (view === 'raw' || typeof last !== 'object') {
      res.innerHTML = '<pre style="margin:0;border-radius:0">' + esc(typeof last === 'object' ? JSON.stringify(last, null, 2) : last) + '</pre>';
      return;
    }
    var rows = last.files || [];
    if (last.takes) rows = last.takes.reduce(function (a, t) {
      return a.concat(t.files.map(function (f) { return Object.assign({}, t, f); }));
    }, []);
    var cols = ['take', 'session', 'type', 'format', 'capture_date', 'gloss', 'signbank_id', 'record_id', 'sentence', 'size_bytes', 'url'];
    var html = '<table><thead><tr>' + cols.map(function (c) { return '<th>' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
    rows.forEach(function (r) {
      html += '<tr>' + cols.map(function (c) {
        var v = r[c];
        if (c === 'url') return '<td><a href="' + esc(v) + '" target="_blank" rel="noopener">download</a></td>';
        if (c === 'size_bytes') return '<td>' + size(v) + '</td>';
        return '<td>' + esc(v) + '</td>';
      }).join('') + '</tr>';
    });
    res.innerHTML = html + '</tbody></table>';
  }

  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var st = document.getElementById('status'), run = document.getElementById('run');
    run.disabled = true; st.className = 'status'; st.textContent = 'Running…';
    var t0 = performance.now();
    fetch(url(), { credentials: 'same-origin' }).then(function (r) {
      var ct = r.headers.get('content-type') || '';
      return (ct.indexOf('json') >= 0 ? r.json() : r.text()).then(function (body) { return [r.status, body]; });
    }).then(function (x) {
      var ms = Math.round(performance.now() - t0);
      last = x[1];
      document.getElementById('tabs').hidden = typeof last !== 'object' || !!last.error;
      if (x[0] !== 200) { st.className = 'status err'; st.textContent = x[0] + ' — ' + (last.message || last); view = 'raw'; }
      else if (typeof last === 'object') {
        st.className = 'status ok';
        st.textContent = (last.takes ? last.count + ' of ' + last.total_takes + ' takes shown (' + last.total_files + ' files'
                                     : last.count + ' of ' + last.total_files + ' files shown (' + last.total_takes + ' takes')
                         + ', ' + size(last.total_bytes) + ') · ' + ms + ' ms';
      } else { st.className = 'status ok'; st.textContent = 'Text response · ' + ms + ' ms'; view = 'raw'; }
      render();
    }).catch(function (err) { st.className = 'status err'; st.textContent = String(err); })
      .finally(function () { run.disabled = false; });
  });
  document.getElementById('clear').addEventListener('click', function () { form.reset(); refresh(); });
  document.getElementById('presets').addEventListener('click', function (e) {
    var q = e.target.getAttribute('data-q'); if (!q) return;
    form.reset();
    new URLSearchParams(q).forEach(function (v, k) { if (form.elements[k]) form.elements[k].value = v; });
    refresh(); form.requestSubmit();
  });
  document.getElementById('tabs').addEventListener('click', function (e) {
    var t = e.target.getAttribute('data-t'); if (!t) return;
    view = t;
    this.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b === e.target); });
    render();
  });
  function copy(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
      var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = o; }, 1200);
    });
  }
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () { copy(document.querySelector(b.getAttribute('data-copy')).textContent, b); });
  });
  document.getElementById('copycurl').addEventListener('click', function () {
    copy('curl -H "X-API-Key: ' + KEY + '" "' + url() + '"', this);
  });
  refresh();
})();
</script>
</body>
</html>
