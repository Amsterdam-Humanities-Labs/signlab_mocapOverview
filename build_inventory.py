#!/usr/bin/env python3
"""
Build data/inventory.json for the mocap dataset overview (index.php).

Walks every storage location that holds motion-capture data or media linked to
it, assigns each file to a capture session (Zin in NGT, BAK, 3DLEX, LSC/LSE,
other) and to a data kind, and writes counts, sizes and distinct takes.

Run:  python3 /web/mocapOverview/build_inventory.py               full build (~30 min, NAS is slow)
      python3 /web/mocapOverview/build_inventory.py --files-only  only the API file index (~5 min)
Log:  data/build.log

Also (re)builds the MySQL table mocapoverview_files that the API in api/ queries.

Session rules (see SESSION_INFO below):
  * Broadcast-named files (M20260113_9959[_260319_0]) are classified through
    matched_transcriptions.m_file: zOg 'zin' -> Zin in NGT; zOg 'labels'/'extern'
    with form_data label "Basiswoordenlijst Amsterdamse Kleuters" -> BAK.
    Broadcasts that never got a mocap take count as 'nomocap'.
  * Gloss-named files (TAART-B_241127_0_...) -> 3DLEX when the gloss is an NGT
    Signbank gloss (mocap_data) or an upper-case gloss; -> LSC/LSE when it is in
    csl_glosses or was recorded 3–19 Feb 2025 with a non-uppercase name.
  * test* names -> other.
"""
import collections
import json
import os
import re
import struct
import sys
import time
import urllib.parse

import pymysql

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, 'data')
OUT = os.path.join(DATA, 'inventory.json')
GLB_CACHE = os.path.join(DATA, 'glb_rig_cache.json')

FBX = '/mnt/bigstorage/fbx'                       # == /web/gebarenoverleg_media/fbx
MEDIA = '/web/gebarenoverleg_media'
SFM = '/mnt/bigstorage/studioFilesMini'           # == /web/gebarenoverleg_media/studioFilesMini

SESSION_INFO = [
    {'id': 'zin', 'name': 'Zin in NGT', 'period': 'Nov 2025 – ongoing',
     'desc': 'Full NGT sentences from the Zin corpus (zinnen.html). Takes are named after the sentence '
             'video broadcast: M{video}_{id}_{capture YYMMDD}_{take}.'},
    {'id': 'bak', 'name': 'BAK', 'period': 'Apr 2026 – ongoing',
     'desc': 'Basiswoordenlijst Amsterdamse Kleuters: single signs from the kindergarten word list '
             '(form_data label, groups M1*/M2*). Same broadcast naming as Zin.'},
    {'id': '3dlex', 'name': '3DLEX', 'period': '2024 – Aug 2025',
     'desc': 'NGT lexicon: isolated Signbank glosses captured on the first pipeline '
             '(first ngt_gomer_{GLOSS} tests in 2024, then Unreal + ReadyPlayerMe "glassesGuy" avatar with iPhone '
             'LiveLink face from Nov 2024). Named {GLOSS}_{YYMMDD}_{take}.'},
    {'id': 'lsc', 'name': 'LSC / LSE (Spain)', 'period': 'Feb 2025',
     'desc': 'Catalan Sign Language glosses (csl_glosses), Spanish/Catalan sentences and handshapes '
             'recorded in the Feb 2025 session. Named {Gloss}_{YYMMDD}_{take}.'},
    {'id': 'other', 'name': 'Tests & pilots', 'period': '2024 – ongoing',
     'desc': 'Test takes, calibration, pilots (Heks, Leiden, markt, kids sentences) and '
             'broadcasts without a matching transcription.'},
    {'id': 'nomocap', 'name': 'No mocap', 'period': '',
     'desc': 'Reference videos of signCollect recordings that have no motion-capture take (yet).'},
]
SESSIONS = [s['id'] for s in SESSION_INFO]

BC_RE = re.compile(r'^([A-Z])(\d{8}_\d{4})(?:_(\d{6})_(\d+))?')
GLOSS_RE = re.compile(r'^(.+?)_(\d{6})_(\d+)')
# LiveLink-app names with the date glued to the gloss: Jardidecasa2502030_0 = Jardidecasa, 250203, take 0
GLUED_RE = re.compile(r'^([A-Za-z0-9-]*?[A-Za-z-])(2[45][01]\d[0-3]\d)(\d+)_(\d+)')
DATE_PREFIX_RE = re.compile(r'^20\d{6}_')
TAKE_SUFFIX_RE = re.compile(
    r'(_(Glasses|glasses)Guy(Record_C_\d)?|_WitchRecord_C_\d|_anim|_shapekeys|_keypoints(_signs)?'
    r'|_Signbank_ID_glossen|_Gebaar-voor-gebaar|_Nederlands|_(LEFT|MIDDLE|RIGHT)(_\d{4}-\d\d-\d\d_[\d-]+)?'
    r'|_\d{4}-\d\d-\d\d_\d\d-\d\d-\d\d)+$')

LOG = open(os.path.join(DATA, 'build.log'), 'a')


def log(*a):
    msg = time.strftime('%Y-%m-%d %H:%M:%S ') + ' '.join(str(x) for x in a)
    print(msg, file=LOG, flush=True)
    if sys.stdout.isatty():
        print(msg, flush=True)


# ---------------------------------------------------------------- database

def db():
    src = open('/web/mysql_config.php').read()
    cfg = dict(re.findall(r'\$(\w+)\s*=\s*"([^"]*)"', src))
    return pymysql.connect(host=cfg['servername'], user=cfg['username'], password=cfg['password'],
                           database=cfg['database'], charset='utf8mb4')


def load_lookups(cur):
    cur.execute("""
        SELECT SUBSTRING_INDEX(mt.m_file, '.', 1), LOWER(mt.zOg),
               MAX(fd.labels LIKE '%%Basiswoordenlijst Amsterdamse Kleuters%%')
        FROM matched_transcriptions mt
        LEFT JOIN form_data fd ON fd.id = CAST(mt.m_transcription AS UNSIGNED)
                              AND mt.zOg IN ('labels', 'extern')
        WHERE mt.m_file LIKE '_20%%'
        GROUP BY 1, 2""")
    bc_cat = {}
    for bc, zog, isbak in cur.fetchall():
        cat = 'zin' if zog == 'zin' else 'bak' if (zog in ('labels', 'extern') and isbak) else 'other'
        # a broadcast listed under several zOg values: prefer the specific session
        if bc_cat.get(bc) in ('zin', 'bak'):
            continue
        bc_cat[bc] = cat
    cur.execute("SELECT glos FROM mocap_data")
    ngt = {r[0] for r in cur.fetchall() if r[0]}
    cur.execute("SELECT glos FROM csl_glosses")
    csl = {r[0] for r in cur.fetchall() if r[0]}
    return bc_cat, ngt, csl


# ------------------------------------------------------------ classification

class Classifier:
    def __init__(self, bc_cat, ngt, csl):
        self.bc_cat, self.ngt, self.csl = bc_cat, ngt, csl
        self.mocap_bcs = set()       # broadcasts with at least one mocap take

    def broadcast(self, name):
        m = BC_RE.match(name)
        return ('M' + m.group(2)) if m else None

    def session(self, name, video=False):
        """Session id for a file or take name. video=True: broadcast-level reference media."""
        m = BC_RE.match(name)
        if m:
            bc = 'M' + m.group(2)
            if video and bc not in self.mocap_bcs:
                return 'nomocap'
            return self.bc_cat.get(bc, 'other')
        base = DATE_PREFIX_RE.sub('', os.path.basename(name))
        if base.lower().startswith('ngt_'):          # 2024 3DLEX captures: ngt_gomer_{GLOSS}
            return '3dlex'
        g = GLOSS_RE.match(base) or GLUED_RE.match(base)
        gloss = g.group(1) if g else TAKE_SUFFIX_RE.sub('', base.rsplit('.', 1)[0])
        date = g.group(2) if g else ''
        if date >= '250901' and not date.startswith('24'):   # gloss-named takes on the Vicon-era system are tests
            return 'other'
        low = gloss.lower()
        if low.startswith(('test', 'triangle')) or 'test' in low[:8]:
            return 'other'
        if gloss in self.csl or ('250203' <= date <= '250219' and not gloss.isupper()):
            return 'lsc'
        if gloss in self.ngt or gloss.lstrip('#').replace('-', '').replace('+', '').isupper():
            return '3dlex'
        if date.startswith('2502') and not gloss.isupper():
            return 'lsc'
        return 'other'

    @staticmethod
    def take(name):
        base = DATE_PREFIX_RE.sub('', os.path.basename(name))
        m = BC_RE.match(base)
        if m and m.group(3):
            return m.group(0)
        g = GLOSS_RE.match(base) or GLUED_RE.match(base)
        if g:
            return '%s_%s_%s' % g.group(1, 2, 3)
        return TAKE_SUFFIX_RE.sub('', base.rsplit('.', 1)[0])


# ----------------------------------------------------------------- counting

class Inventory:
    def __init__(self):
        self.items = collections.OrderedDict()

    def add_item(self, key, **meta):
        meta.setdefault('by_session', {s: {'files': 0, 'bytes': 0, 'takes': 0} for s in SESSIONS})
        meta['_takes'] = collections.defaultdict(set)
        self.items[key] = meta

    def count(self, key, session, size, take=None, n=1):
        c = self.items[key]['by_session'][session]
        c['files'] += n
        c['bytes'] += size
        if take:
            self.items[key]['_takes'][session].add(take)

    def finish(self):
        out = []
        for key, it in self.items.items():
            for s, takes in it.pop('_takes').items():
                it['by_session'][s]['takes'] = len(takes)
            it['total'] = {k: sum(v[k] for v in it['by_session'].values()) for k in ('files', 'bytes', 'takes')}
            it['key'] = key
            out.append(it)
        return out


def scan(path, recursive=False, skip_dirs=()):
    """Yield (relative_name, size, is_symlink) for regular files below path."""
    stack = [path]
    while stack:
        d = stack.pop()
        try:
            it = os.scandir(d)
        except OSError as e:
            log('scan error', d, e)
            continue
        with it:
            for e in it:
                try:
                    if e.is_dir(follow_symlinks=False):
                        if recursive and e.name not in skip_dirs and not e.name.startswith('.'):
                            stack.append(e.path)
                        continue
                    if not e.is_file():
                        continue
                    yield os.path.relpath(e.path, path), e.stat(follow_symlinks=False).st_size
                except OSError:
                    continue


def glb_rig(path, cache):
    """'rpm' | 'cc' | 'vicon' | 'other' — read from the GLB JSON chunk, cached by mtime."""
    name = os.path.basename(path)
    try:
        mt = int(os.stat(path).st_mtime)
    except OSError:
        return 'other'
    hit = cache.get(name)
    if hit and hit[0] == mt:
        return hit[1]
    rig = 'other'
    try:
        with open(path, 'rb') as f:
            f.read(12)
            ln, _ = struct.unpack('<II', f.read(8))
            j = json.loads(f.read(ln))
        names = [n.get('name', '') for n in j.get('nodes', [])]
        if 'glassesGuy' in names or any(n.lower().startswith('wolf3d') for n in names):
            rig = 'rpm'
        elif any(n.startswith('cc_base') for n in names):
            rig = 'cc'
        elif any(n.startswith(('Vero', 'FLIR', 'Vantage')) for n in names) or 'Hips' in names:
            rig = 'vicon'
    except Exception:
        pass
    cache[name] = [mt, rig]
    return rig


# -------------------------------------------------------------------- build

def main():
    t0 = time.time()
    log('build start')
    conn = db()
    cur = conn.cursor()
    bc_cat, ngt, csl = load_lookups(cur)
    C = Classifier(bc_cat, ngt, csl)
    inv = Inventory()

    # ---- capture PC: vicon_files index of E:\Recordings --------------------
    cur.execute("""
        SELECT SUBSTRING_INDEX(capture_id, '/', -1) take, subdirectory,
               LOWER(SUBSTRING_INDEX(filename, '.', -1)) ext, COUNT(*), SUM(size_bytes)
        FROM vicon_files GROUP BY 1, 2, 3""")
    vrows = cur.fetchall()
    for take, sub, ext, n, size in vrows:
        if sub in ('unreal/CC', 'unreal') and ext == 'fbx':
            C.mocap_bcs.add(C.broadcast(take))
    cur.execute("SELECT filename FROM mocap_files")
    for (fn,) in cur.fetchall():
        C.mocap_bcs.add(C.broadcast(fn or ''))
    C.mocap_bcs.discard(None)

    PC = 'Capture PC'
    PCP = r'E:\Recordings\{date}\{take}'
    vicon_kinds = [
        # (sub, exts, key, title, fmt, path suffix, note)
        ('shogun_live', ('x2d',), 'pc_x2d', 'Vicon camera data (X2D)', 'X2D', r'\shogun_live', 'Raw 2D centroid data from the Vicon cameras; the largest raw asset.'),
        ('shogun_live', ('mcp',), 'pc_mcp', 'Shogun Live capture (MCP)', 'MCP', r'\shogun_live', 'Reconstructed marker/solve data, re-processable in Shogun Post.'),
        ('shogun_live', ('enf',), 'pc_enf', 'Shogun take info (ENF)', 'ENF', r'\shogun_live', 'Small per-take index file.'),
        ('shogun_live', ('mov', 'vvid'), 'pc_viconvid', 'Vicon reference video', 'MOV / VVID', r'\shogun_live', 'Video from the Vicon Vue reference cameras (MOV until Mar 2026, VVID from May 2026).'),
        ('shogun_live', ('capture',), 'pc_capture', 'Shogun .capture bundles', 'CAPTURE', r'\shogun_live', ''),
        ('shogun_post', ('fbx',), 'pc_shogunpost', 'Shogun Post export', 'FBX + JSON', r'\shogun_post', 'Vicon skeleton solved in Shogun Post (Oct 2025 – Mar 2026).'),
        ('unreal', ('fbx',), 'pc_unreal_rpm', 'Unreal export — ReadyPlayerMe', 'FBX', r'\unreal', 'Retargeted in Unreal onto the ReadyPlayerMe "glassesGuy" avatar.'),
        ('unreal/CC', ('fbx',), 'pc_unreal_cc', 'Unreal export — CC (Palmer)', 'FBX', r'\unreal\CC', 'Retargeted onto the Character Creator avatar; source for animMIDI.'),
        ('unreal/Vicon', ('fbx',), 'pc_unreal_vicon', 'Unreal export — Vicon skeleton', 'FBX', r'\unreal\Vicon', 'Plain Vicon skeleton, from Feb 2026.'),
        ('livelink', ('csv',), 'pc_livelink', 'LiveLink face (iPhone ARKit)', 'CSV', r'\unreal\*_iPhone.csv', '52 ARKit blendshape curves per frame.'),
        ('obs', ('mkv',), 'pc_obs', 'OBS webcam recordings', 'MKV', r'\obs', 'Razer webcams LEFT / MIDDLE / RIGHT recorded by OBS; copied to razerFiles on the server.'),
        ('metadata', ('json',), 'pc_meta', 'Recording metadata', 'JSON', r'\metadata', 'record_*.json: timing, operator, sentence/label.'),
    ]
    idx = {}
    for sub, exts, key, title, fmt, suffix, note in vicon_kinds:
        inv.add_item(key, group='raw', title=title, fmt=fmt, host=PC, path=PCP + suffix, note=note)
        for e in exts:
            idx[(sub, e)] = key
    inv.add_item('pc_batch', group='raw', title='Batch exports / archives', fmt='ZIP / FBX / MKV', host=PC,
                 path=r'E:\Recordings\{date}\<batch folder>', note='Loose files in date folders (e.g. all_CC_anims, zipped exports Nov–Dec 2025).')
    for take, sub, ext, n, size in vrows:
        key = idx.get((sub, ext)) or ('pc_batch' if sub == 'root' else None)
        if not key:
            continue
        inv.count(key, C.session(take), int(size or 0), take=take, n=int(n))

    # ---- legacy pipeline DB (mocap_files) ----------------------------------
    inv.add_item('db_mocapfiles', group='raw', title='First-pipeline take register (mocap_files)', fmt='DB rows',
                 host='MySQL', path='admin_gebarenoverleg.mocap_files',
                 note='Takes of the Nov 2024 – Dec 2025 pipeline. Vicon FBX/CSV paths listed here pointed to '
                      '/web/gebarenoverleg_media/vicon/, which is now empty.')
    cur.execute("SELECT filename, datetime FROM mocap_files")
    for fn, dt in cur.fetchall():
        fn = fn or ''
        inv.count('db_mocapfiles', C.session(fn), 0, take=C.take(fn))

    conn.close()
    log('db done', round(time.time() - t0), 's')

    # ---- server: animation files ------------------------------------------
    URL_FBX = '/gebarenoverleg_media/fbx/'
    anim = [
        ('rpm_fbx', 'ReadyPlayerMe avatar — raw', 'FBX', FBX + '/', URL_FBX,
         'glassesGuy avatar (RPM rig: Hips, Spine, LeftEye). 3DLEX / LSC takes end in _GlassesGuyRecord_C_1.', 'rpm', 'raw'),
        ('rpm_glb', 'ReadyPlayerMe avatar — raw', 'GLB', FBX + '/', URL_FBX, 'GLB includes the avatar mesh (~7.5 MB each).', 'rpm', 'raw'),
        ('rpm_pp_fbx', 'ReadyPlayerMe avatar — post-processed', 'FBX', FBX + '/post_processed/', URL_FBX + 'post_processed/',
         'First-pipeline takes (3DLEX, early Zin) cleaned in Unreal; same folder as CC post-processed files.', 'rpm', 'pp'),
        ('vsys_glb', 'Vicon skeleton — raw (first pipeline)', 'FBX + GLB', FBX + '/', URL_FBX,
         'Files without the _GlassesGuyRecord suffix (e.g. TAART-B_241127_0.glb): the Shogun skeleton of the same '
         '3DLEX/LSC take, some with the camera rig; also Oline test exports.', 'vicon', 'raw'),
        ('cc_raw', 'CC avatar (Palmer) — raw', 'FBX + GLB', FBX + '/CC/', URL_FBX + 'CC/',
         'Character Creator rig (cc_base_*), metres. Downloaded by engineers in animMIDI.', 'cc', 'raw'),
        ('cc_pp', 'CC avatar (Palmer) — post-processed', 'FBX + GLB', FBX + '/post_processed/', URL_FBX + 'post_processed/',
         'Cleaned in Unreal and uploaded back via animMIDI (vicon_files.is_pp).', 'cc', 'pp'),
        ('ccp_raw', 'CC + face shapekeys — raw', 'GLB + JSON', FBX + '/cc_pipeline/', URL_FBX + 'cc_pipeline/',
         '*_anim.glb body animation plus *_shapekeys.json face curves, for the web viewers.', 'cc', 'raw'),
        ('ccp_pp', 'CC + face shapekeys — post-processed', 'GLB + JSON', FBX + '/cc_pipeline_pp/', URL_FBX + 'cc_pipeline_pp/',
         'Same, built from the post-processed FBX.', 'cc', 'pp'),
        ('vicon_raw', 'Vicon skeleton — raw', 'FBX + GLB', FBX + '/Vicon/', URL_FBX + 'Vicon/',
         'Unretargeted Vicon skeleton (from Feb 2026).', 'vicon', 'raw'),
    ]
    for key, title, fmt, path, url, note, avatar, stage in anim:
        inv.add_item(key, group='animation', title=title, fmt=fmt, host='Server', path=path, url=url,
                     note=note, avatar=avatar, stage=stage)
    derived = [
        ('hamer', 'HaMeR hand-pose estimates', '.hamer', 'Per-take hand mesh recovery from video.'),
        ('kpts', 'Keypoints', '_keypoints.npz / _keypoints_signs.json', 'Body/hand keypoints and sign segmentation.'),
        ('vtt', 'Subtitle cues', '.vtt', ''),
    ]
    for key, title, fmt, note in derived:
        inv.add_item(key, group='derived', title=title, fmt=fmt, host='Server', path=FBX + '/', url=URL_FBX, note=note)

    try:
        cache = json.load(open(GLB_CACHE))
    except Exception:
        cache = {}

    # root: classify GLBs by rig, FBX by their GLB sibling
    root = list(scan(FBX))
    glb_rig_by_stem = {}
    for name, size in root:
        if name.lower().endswith('.glb'):
            glb_rig_by_stem[name[:-4]] = glb_rig(os.path.join(FBX, name), cache)
    for name, size in root:
        low = name.lower()
        s = C.session(name)
        t = C.take(name)
        if low.endswith('.glb'):
            rig = glb_rig_by_stem[name[:-4]]
            key = {'rpm': 'rpm_glb', 'cc': 'cc_raw', 'vicon': 'vsys_glb'}.get(rig, 'rpm_glb')
        elif low.endswith('.fbx'):
            # FBX without a GLB sibling: the root only holds Unreal/RPM exports otherwise
            rig = glb_rig_by_stem.get(name[:-4], 'rpm')
            key = {'rpm': 'rpm_fbx', 'cc': 'cc_raw', 'vicon': 'vsys_glb'}.get(rig, 'rpm_fbx')
        elif low.endswith('.hamer'):
            key = 'hamer'
        elif '_keypoints' in low:
            key = 'kpts'
        elif low.endswith('.vtt'):
            key = 'vtt'
        else:
            continue
        inv.count(key, s, size, take=t)

    for sub, key in (('CC', 'cc_raw'), ('cc_pipeline', 'ccp_raw'), ('cc_pipeline_pp', 'ccp_pp'), ('Vicon', 'vicon_raw')):
        for name, size in scan(os.path.join(FBX, sub)):
            if name.lower().endswith(('.fbx', '.glb', '.json')):
                inv.count(key, C.session(name), size, take=C.take(name))

    # post_processed: CC rig for the Vicon-era takes, RPM for first-pipeline takes
    pp = os.path.join(FBX, 'post_processed')
    pp_list = [(n, s) for n, s in scan(pp) if n.lower().endswith(('.fbx', '.glb'))]
    pp_rig = {n[:-4]: glb_rig(os.path.join(pp, n), cache) for n, s in pp_list if n.lower().endswith('.glb')}
    for name, size in pp_list:
        rig = pp_rig.get(name[:-4], 'cc')
        inv.count('rpm_pp_fbx' if rig == 'rpm' else 'cc_pp', C.session(name), size, take=C.take(name))

    json.dump(cache, open(GLB_CACHE, 'w'))
    log('animation done', round(time.time() - t0), 's')

    # ---- video derived animation ---------------------------------------------
    inv.add_item('s3d', group='derived', title='SAM 3D Body animation (from video)', fmt='GLB', host='Server',
                 path='/mnt/bigstorage/s3d_files/', note='Markerless body estimate from the L/M/R webcam videos; not mocap.')
    for name, size in scan('/mnt/bigstorage/s3d_files'):
        inv.count('s3d', C.session(name, video=True), size, take=C.broadcast(name))

    # ---- annotation ---------------------------------------------------------
    EAF = '/web/zin/eaf/zin/'
    inv.add_item('eaf_take', group='annotation', title='ELAN annotation — take level', fmt='EAF', host='Server',
                 path=EAF, url='/zin/eaf/zin/', note='{take}.eaf, timed to one mocap take. Required for the "MCP Klaar+EAF" filter.')
    inv.add_item('eaf_bc', group='annotation', title='ELAN annotation — video level', fmt='EAF', host='Server',
                 path=EAF, url='/zin/eaf/zin/', note='{broadcast}.eaf, timed to the reference video, not to a take.')
    inv.add_item('srt', group='annotation', title='Subtitle tiers', fmt='SRT', host='Server', path=EAF, url='/zin/eaf/zin/',
                 note='Per tier: _Nederlands, _Signbank_ID_glossen, _Gebaar-voor-gebaar.')
    inv.add_item('eaf_bak', group='annotation', title='Annotation backups', fmt='EAF / SRT', host='Server', path=EAF,
                 note='*_backup_{timestamp}.* versions written on every save; not counted as data.')
    for name, size in scan(EAF):
        low = name.lower()
        if not low.endswith(('.eaf', '.srt')):
            continue
        if '_backup' in low:
            key = 'eaf_bak'
        elif low.endswith('.srt'):
            key = 'srt'
        else:
            m = BC_RE.match(name)
            key = 'eaf_take' if (m and m.group(3)) or GLOSS_RE.match(name) else 'eaf_bc'
        inv.count(key, C.session(name, video=not (BC_RE.match(name) and BC_RE.match(name).group(3))), size,
                  take=C.take(name))

    # ---- video --------------------------------------------------------------
    videos = [
        ('razer', 'OBS webcam recordings (Razer)', 'MKV', '/mnt/bigstorage/razerFiles/', '/gebarenoverleg_media/razerFiles/',
         'Per take, LEFT / MIDDLE / RIGHT; server copy of the capture-PC obs folder.', False, ('.mkv', '.mov')),
        ('bm_mini', 'Blackmagic studio camera — preview', 'MP4', MEDIA + '/blackamgic_filesMini/{date}/', '/gebarenoverleg_media/blackamgic_filesMini/',
         'Downscaled per-take previews, from May 2026 (folder name misspelled on purpose).', True, ('.mp4',)),
        ('bm_full', 'Blackmagic studio camera — full resolution', 'MP4 / MOV', MEDIA + '/studioFiles/blackmagic_files/',
         None, 'On the university project share (AIHR-FGW-TEST-SIGNLAB), mounted on the server; share is full.', True, ('.mp4', '.mov', '.braw', '.mxf')),
        ('sfm_raw', 'Reference videos — raw', 'MP4', SFM + '/raw/', '/gebarenoverleg_media/studioFilesMini/raw/',
         'The signCollect sentence/sign videos the mocap takes are based on (L/M/R webcams, per broadcast).', False, ('.mp4',)),
        ('sfm_post', 'Reference videos — processed', 'MP4', SFM + '/post/', '/gebarenoverleg_media/studioFilesMini/post/',
         'Trimmed/re-encoded versions used by zinnen.html and the annotation tools.', False, ('.mp4',)),
        ('sfm_tyd', 'Reference videos — TYD app', 'MP4', SFM + '/tyd/', '/gebarenoverleg_media/studioFilesMini/tyd/', '', False, ('.mp4',)),
        ('llvid', 'iPhone LiveLink face video', 'MP4', MEDIA + '/llVideos/', '/gebarenoverleg_media/llVideos/',
         'Face camera video of the first pipeline (3DLEX / LSC era).', True, ('.mp4',)),
        ('v2v', 'Video-to-video avatar renders', 'MP4', '/mnt/bigstorage/v2v_videos/', '/gebarenoverleg_media/v2v_videos/',
         'Generated boy/girl avatar videos from the reference videos.', False, ('.mp4',)),
    ]
    for key, title, fmt, path, url, note, recursive, exts in videos:
        inv.add_item(key, group='video', title=title, fmt=fmt, host='NAS share' if key == 'bm_full' else 'Server',
                     path=path, url=url, note=note)
        real = path.split('{')[0]
        for name, size in scan(real, recursive=recursive, skip_dirs=('lost+found', 'remove', 'temp', 'backup_borders')):
            if not name.lower().endswith(exts):
                continue
            base = os.path.basename(name)
            take_level = bool(BC_RE.match(base) and BC_RE.match(base).group(3)) or bool(GLOSS_RE.match(base))
            inv.count(key, C.session(base, video=not take_level), size,
                      take=C.take(base) if take_level else C.broadcast(base))
        log(key, 'done', round(time.time() - t0), 's')

    # ---- CSV / metadata -----------------------------------------------------
    inv.add_item('llcsv', group='csv', title='LiveLink face curves (server copy)', fmt='CSV', host='Server',
                 path=MEDIA + '/llcsv/', url='/gebarenoverleg_media/llcsv/',
                 note='ARKit blendshapes per take (*_iPhone.csv); 3DLEX-era files end in _iPhone_raw.csv.')
    for name, size in scan(MEDIA + '/llcsv', recursive=True):
        if name.lower().endswith('.csv'):
            b = os.path.basename(name)
            inv.count('llcsv', C.session(b), size, take=C.take(b.replace('_iPhone_raw', '').replace('_iPhone', '')))
    inv.add_item('meta', group='csv', title='Take metadata (server copy)', fmt='JSON', host='Server',
                 path=MEDIA + '/metadata/', note='*-footTiming.json, rpm-timeInfo-*.json, record_*.json.')
    for name, size in scan(MEDIA + '/metadata'):
        b = re.sub(r'^rpm-timeInfo-', '', name).replace('-footTiming', '')
        inv.count('meta', C.session(b), size, take=C.take(b) if BC_RE.match(b) else None)
    inv.add_item('shogun_zip', group='raw', title='Shogun backups (figshare)', fmt='ZIP', host='Server',
                 path='/mnt/bigstorage/figshare_shogun_backup/', note='ZNN_shogun_{date}.zip archives of Shogun sessions.')
    for name, size in scan('/mnt/bigstorage/figshare_shogun_backup'):
        inv.count('shogun_zip', 'other', size)

    # ---- university project share (NAS) ------------------------------------
    NAS = MEDIA + '/studioFiles'
    nas = [
        # key, group, title, fmt, subpaths, exts, forced session, note
        ('nas_3dlex_anim', 'animation', '3DLEX 2024 captures', 'FBX / GLB', ['/mocapFiles/3DLEX'], ('.fbx', '.glb'), '3dlex',
         'Early lexicon captures ngt_gomer_{GLOSS}, before the take-recorder pipeline.'),
        ('nas_first_raw', 'raw', 'Raw capture backup (NAS)', 'MCP / X2D / FBX / MKV / CSV',
         ['/mocapFiles/shogun_live', '/mocapFiles/unreal', '/mocapFiles/obs', '/mocapFiles/livelink', '/mocapFiles/metadata'],
         None, None, 'Copy of the capture-PC recordings (Shogun, Unreal, OBS, LiveLink, metadata) for both pipelines, '
                     'incl. the Vicon-era Zin and BAK takes; same folder split as the capture PC.'),
        ('nas_packages', 'raw', 'Mocap data packages', 'ZIP', ['/mocapDataPackages'], ('.zip',), None,
         'One zip per take (Shogun + OBS + Unreal + metadata), made for Zin takes Nov–Dec 2025.'),
        ('nas_3dlex_video', 'video', '3DLEX studio cameras', 'MP4', ['/3DLEX'], ('.mp4',), '3dlex',
         'videoLeft / videoCenter / videoRight camera clips (camera clip names, e.g. C1416.MP4).'),
        ('nas_obs_first', 'video', 'OBS webcam recordings — first pipeline', 'MKV', ['/mocap_videos'], ('.mkv', '.mp4'), None,
         'LEFT / MIDDLE / RIGHT webcams of the 3DLEX and LSC takes (Feb 2025).'),
        ('nas_lltakes', 'csv', 'LiveLink Face takes (iPhone app)', 'CSV / MOV / JSON', ['/llTakes'], ('.csv', '.mov', '.json'), None,
         'Face-capture takes as saved by the Live Link Face app: blendshape CSV plus face video.'),
    ]
    for key, group, title, fmt, subs, exts, forced, note in nas:
        inv.add_item(key, group=group, title=title, fmt=fmt, host='NAS share',
                     path=' · '.join(NAS + p + '/' for p in subs), note=note)
        for p in subs:
            for name, size in scan(NAS + p, recursive=True, skip_dirs=('backup',)):
                if exts and not name.lower().endswith(exts):
                    continue
                base = os.path.basename(name)
                # camera clip names (C1416.MP4) carry no take name
                inv.count(key, forced or C.session(base), size, take=None if forced == '3dlex' and key.endswith('video') else C.take(base))
        log(key, 'done', round(time.time() - t0), 's')

    items = inv.finish()
    result = {
        'generated': time.strftime('%Y-%m-%d %H:%M'),
        'build_seconds': round(time.time() - t0),
        'sessions': SESSION_INFO,
        'items': items,
    }
    tmp = OUT + '.tmp'
    json.dump(result, open(tmp, 'w'), indent=1)
    os.replace(tmp, OUT)
    log('build done', result['build_seconds'], 's')


# --------------------------------------------------------------- file index
# One row per animation file on the server, queried by api/index.php.
# Built into a staging table and swapped in with RENAME, so the API never sees a half-built index.

INDEX_TABLE = 'mocapoverview_files'
INDEX_DDL = """
CREATE TABLE {t} (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file VARCHAR(255) NOT NULL,
  take VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL COMMENT 'gloss for gloss takes, reference video (broadcast) otherwise',
  session VARCHAR(16) NOT NULL,
  type VARCHAR(16) NOT NULL,
  avatar VARCHAR(8) NOT NULL,
  stage VARCHAR(4) NOT NULL,
  format VARCHAR(12) NOT NULL,
  capture_date DATE NULL,
  take_no INT NULL,
  gloss VARCHAR(255) NULL COMMENT 'the sign, for 3DLEX / LSC / BAK',
  signbank_id INT NULL,
  record_id INT NULL COMMENT 'sentences.ID (Zin) or form_data.id (BAK)',
  sentence TEXT NULL,
  glosses TEXT NULL COMMENT 'Zin: space-separated glosses of the sentence, padded with spaces',
  size_bytes BIGINT NOT NULL,
  modified DATETIME NOT NULL,
  path VARCHAR(512) NOT NULL,
  url VARCHAR(512) NOT NULL,
  KEY k_gloss (gloss), KEY k_name (name), KEY k_take (take), KEY k_date (capture_date),
  KEY k_type (type), KEY k_session (session), KEY k_sb (signbank_id), KEY k_rec (record_id)
) DEFAULT CHARSET=utf8mb4 COMMENT='Animation file index for /mocapOverview/api, rebuilt by build_inventory.py'
"""

INDEX_DIRS = [
    # subdir, type by rig (None = decide from GLB rig), stage
    ('', None, 'raw'),
    ('CC', 'cc_raw', 'raw'),
    ('post_processed', None, 'pp'),
    ('cc_pipeline', 'ccp_raw', 'raw'),
    ('cc_pipeline_pp', 'ccp_pp', 'pp'),
    ('Vicon', 'vicon_raw', 'raw'),
]
TYPE_AVATAR = {'cc_raw': 'cc', 'cc_pp': 'cc', 'ccp_raw': 'cc', 'ccp_pp': 'cc', 'rpm_raw': 'rpm', 'rpm_pp': 'rpm',
               'vicon_raw': 'vicon'}


def build_file_index(conn, C):
    t0 = time.time()
    cur = conn.cursor()
    # enrichment lookups
    cur.execute("""
        SELECT SUBSTRING_INDEX(mt.m_file, '.', 1), LOWER(mt.zOg), CAST(mt.m_transcription AS UNSIGNED),
               s.zinString, s.glosses, fd.glos
        FROM matched_transcriptions mt
        LEFT JOIN sentences s ON LOWER(mt.zOg) = 'zin' AND s.ID = CAST(mt.m_transcription AS UNSIGNED)
        LEFT JOIN form_data fd ON mt.zOg IN ('labels', 'extern') AND fd.id = CAST(mt.m_transcription AS UNSIGNED)
        WHERE mt.m_file LIKE '_20%%'""")
    bc_info = {}
    for bc, zog, rec, zin, glosses, fglos in cur.fetchall():
        if bc in bc_info and bc_info[bc]['zog'] == 'zin':
            continue
        gl = None
        if glosses:
            try:
                gl = ' ' + ' '.join(g for g in json.loads(glosses) if g and g != 'nvt') + ' '
            except ValueError:
                gl = None
        bc_info[bc] = {'zog': zog, 'record_id': rec or None, 'sentence': zin, 'glosses': gl, 'gloss': fglos}
    cur.execute("SELECT glos, MIN(gloss_id) FROM mocap_data GROUP BY glos")
    sb_ngt = dict(cur.fetchall())
    cur.execute("SELECT glos, MIN(gloss_id) FROM csl_glosses GROUP BY glos")
    sb_csl = dict(cur.fetchall())
    try:
        cache = json.load(open(GLB_CACHE))
    except Exception:
        cache = {}

    rows = []
    for sub, fixed_type, stage in INDEX_DIRS:
        d = os.path.join(FBX, sub) if sub else FBX
        files = [(n, s) for n, s in scan(d) if n.lower().endswith(('.fbx', '.glb', '.json'))]
        rigs = {}
        if fixed_type is None:
            for n, s in files:
                if n.lower().endswith('.glb'):
                    rigs[n[:-4]] = glb_rig(os.path.join(d, n), cache)
        for name, size in files:
            low = name.lower()
            if low.endswith('.json') and not sub.startswith('cc_pipeline'):
                continue
            if fixed_type:
                typ = fixed_type
            else:
                rig = rigs.get(name[:-4], 'cc' if stage == 'pp' else 'rpm')
                typ = {'rpm': 'rpm', 'cc': 'cc', 'vicon': 'vicon'}.get(rig, 'rpm') + '_' + stage
                if typ == 'vicon_pp':
                    typ = 'cc_pp'
            fmt = 'shapekeys' if low.endswith('_shapekeys.json') else low.rsplit('.', 1)[-1]
            base = DATE_PREFIX_RE.sub('', name)
            take = C.take(base)
            session = C.session(base)
            cdate = take_no = gloss = sb = rec = sentence = glosses = None
            m = BC_RE.match(base)
            if m:
                key = 'M' + m.group(2)
                if m.group(3):
                    cdate, take_no = m.group(3), int(m.group(4))
                info = bc_info.get(key, {})
                rec, sentence, glosses = info.get('record_id'), info.get('sentence'), info.get('glosses')
                if session == 'bak':
                    gloss = info.get('gloss')
            else:
                g = GLOSS_RE.match(base) or GLUED_RE.match(base)
                if g:
                    key, cdate, take_no = g.group(1), g.group(2), int(g.group(3))
                else:
                    key = TAKE_SUFFIX_RE.sub('', base.rsplit('.', 1)[0])
                if key.lower().startswith('ngt_gomer_'):
                    key = key[10:]
                gloss = key
                sb = sb_ngt.get(key) if session == '3dlex' else sb_csl.get(key) if session == 'lsc' else None
            if cdate:
                try:
                    cdate = time.strftime('%Y-%m-%d', time.strptime(cdate, '%y%m%d'))
                except ValueError:
                    cdate = None
            full = os.path.join(d, name)
            try:
                mtime = time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(os.stat(full).st_mtime))
            except OSError:
                continue
            rel = (sub + '/' if sub else '') + name
            rows.append((name, take, key[:255], session, typ, TYPE_AVATAR[typ], stage, fmt, cdate, take_no,
                         gloss[:255] if gloss else None, sb, rec, sentence, glosses, size, mtime,
                         '/web/gebarenoverleg_media/fbx/' + rel,
                         'https://signcollect.nl/gebarenoverleg_media/fbx/' + urllib.parse.quote(rel)))
    json.dump(cache, open(GLB_CACHE, 'w'))

    stage_t = INDEX_TABLE + '_new'
    cur.execute('DROP TABLE IF EXISTS ' + stage_t)
    cur.execute(INDEX_DDL.format(t=stage_t))
    sql = ('INSERT INTO ' + stage_t + ' (file, take, name, session, type, avatar, stage, format, capture_date, take_no, '
           'gloss, signbank_id, record_id, sentence, glosses, size_bytes, modified, path, url) '
           'VALUES (' + ','.join(['%s'] * 19) + ')')
    for i in range(0, len(rows), 2000):
        cur.executemany(sql, rows[i:i + 2000])
    conn.commit()
    cur.execute("SHOW TABLES LIKE %s", (INDEX_TABLE,))
    if cur.fetchone():
        cur.execute('DROP TABLE IF EXISTS ' + INDEX_TABLE + '_old')
        cur.execute('RENAME TABLE {t} TO {t}_old, {t}_new TO {t}'.format(t=INDEX_TABLE))
        cur.execute('DROP TABLE ' + INDEX_TABLE + '_old')
    else:
        cur.execute('RENAME TABLE {t}_new TO {t}'.format(t=INDEX_TABLE))
    conn.commit()
    log('file index:', len(rows), 'rows in', round(time.time() - t0), 's')


def files_only():
    conn = db()
    cur = conn.cursor()
    C = Classifier(*load_lookups(cur))
    build_file_index(conn, C)
    conn.close()


if __name__ == '__main__':
    if '--files-only' in sys.argv:
        files_only()
    else:
        main()
        files_only()
