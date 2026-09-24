# signlab_mocapOverview

An overview of the motion-capture dataset, plus a search API for its files.
Built for Jari Andersen and Mabel, to discover and look up mocap files.

## What it does
- `index.php`: what data exists per capture session (Zin in NGT, BAK, 3DLEX, LSC/LSE, other), with counts, sizes and where it lives.
- `api/files`: search animation files; `api/meta`: types, sessions, date range and counts. `api/` is the documentation and playground.
- `build_inventory.py`: walks every storage location, classifies each file by session and kind, writes `data/inventory.json` and rebuilds the MySQL table `mocapoverview_files` the API reads.

## Where it runs
The core server, `/web/mocapOverview`, at signcollect.nl/mocapOverview/. Copied read-only from there on 2026-09-23; the server still runs its own copy.
Demo hosts get it from the stack's deploy (`repos.tsv` row `mocapOverview`); there the page asks for its password, the API needs a key, and the inventory stays empty until `build_inventory.py` has run.

User documentation: https://amsterdam-humanities-labs.github.io/signlab_docs/interfaces/mocap-overview/

## Status
Production.

## How to run or deploy
```
python3 build_inventory.py              # full build (~30 min; the NAS is slow)
python3 build_inventory.py --files-only # only the API file index (~5 min)
```
Log: `data/build.log`.

## Configuration
- Pages: one password, stored as a bcrypt hash in `lib/auth.php` (how to change it is in that file).
- API: a key in the `X-API-Key` header (or `?key=`), listed in `data/api_keys.json` (not in git, not web-readable).
- Database login: read from the server's `mysql_config.php`.
- `data/` (inventory, caches, logs, API keys) is runtime state and not in git.

## Dependencies
MySQL (`matched_transcriptions`, `form_data`, `mocapoverview_files`), the media and FBX storage on the core server, Python 3.

## License and citation

Apache License 2.0, copyright University of Amsterdam: see [LICENSE](LICENSE) and
[NOTICE](NOTICE). You may use it, also commercially, as long as you credit
Gomer Otterspeer / University of Amsterdam as the source. To cite it, use
[CITATION.cff](CITATION.cff) (the *Cite this repository* button on GitHub) or the DOI [10.21942/uva.33980347](https://doi.org/10.21942/uva.33980347).
