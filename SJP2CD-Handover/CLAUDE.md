# Working on this project

Read this first, then `HANDOFF.md` for where things stand and what is still open.
`SPEC.md` holds the decisions and why they were made — check it before changing
behaviour, because most surprises in this code are deliberate.

## What this is

The institutional repository of **St. John Paul II College of Davao**: a capstone project
that stores, describes and preserves theses, capstone projects and faculty research.
Three objectives drive everything:

1. Upload, storage and management of research
2. Dublin Core, PREMIS and METS for discoverability and long-term preservation
3. An interface that encourages **faculty self-archiving**

The defence is close. Prefer finishing and verifying over adding.

## Where it lives

| | |
|---|---|
| **The system** | `C:\xampp\htdocs\sjp2cd-repository` — edit here, this is what runs |
| Served at | <http://localhost/sjp2cd-repository/> |
| The research paper | `C:\Users\Admin\Documents\Capstone Research\Research Paper.docx` |
| An old static prototype | `C:\Users\Admin\Documents\Claude code\sjpiicd-research-repository` — **superseded, do not edit** |

## Stack, and what it must keep working without

PHP 8.2 · MariaDB 10.4 · Apache, all from XAMPP. Hand-written CSS and vanilla JS.

**No Composer, no npm, no build step, no CDN JavaScript.** It has to run offline on a
machine with only XAMPP. Three.js is vendored locally for that reason.

## Commands

```bash
# lint everything before saying it works
for f in *.php includes/*.php config/*.php sql/*.php templates/layout/*.php; do C:/xampp/php/php.exe -l "$f"; done
node --check assets/js/ui.js

# scheduled jobs: archive sweep, fixity audit, verified backup
C:/xampp/php/php.exe sql/maintenance.php

# is Google Sign-In configured correctly?
C:/xampp/php/php.exe sql/check-google.php

# sample data (re-runnable; keeps a ledger in sql/seeded-ids.json)
C:/xampp/php/php.exe sql/seed-sample-data.php
```

`install.php` **drops the database**. Never run it, and never suggest it casually.

## House style

- **Comments say why, not what.** Every non-obvious rule in this codebase has a comment
  explaining the reasoning. Match that; do not strip it.
- British spelling in prose, plain language in anything a user reads.
- Write edits as a small Node script in the scratchpad that does exact string
  replacements and reports `ok` or `MISS` per edit, then run it. Long multi-line PHP
  edits through shell heredocs get mangled by quoting — this is learned, not preference.
- Never echo a credential into the terminal or a reply.

## Traps that have cost time before

- **The browser preview pane collapses to a 0×0 viewport.** Pages then look blank and
  animations look broken. Open a fresh tab and set a viewport size before believing any
  visual result.
- **`$$` is the selector helper in `assets/js/ui.js`, not `$`.** Using `$` throws.
- **Assets need cache-busting.** Everything the layouts load carries `?v=<filemtime>`.
  A new asset without it can sit invisible behind a browser cache.
- **`curl -F` treats `;` as its own separator**, so semicolon-separated test values
  (keywords, panel members) silently truncate. Use `--form-string`.
- **MySQL must be running** for anything to work. If pages show "Database unavailable",
  start MySQL in the XAMPP Control Panel.

## Verifying work

Claims need evidence. The established pattern here is: make the change, lint, then
exercise it over HTTP as each role with `curl` and a cookie jar, and check the database
afterwards. Test data created along the way is deleted afterwards, including its files,
notifications and log rows.

Roles to test as: **visitor** (read only), **student** (read and download), **faculty**
(deposit), **admin** (review, publish, manage).
