# SPEC — SJP2CD Academic Research Repository

**Project:** Web-Based Academic Research Repository System
**Institution:** St. John Paul II College of Davao
**Location:** `C:\xampp\htdocs\sjp2cd-repository`
**Status:** baseline built and verified; three items of agreed new work outstanding
**Written:** 6 September 2026 · **Defence:** within one month

---

## CONTEXT

A working system already exists: 21 PHP pages, 18 MySQL tables, all three capstone
objectives implemented and verified end to end.

It was built conversationally over many sessions, which produced rework — a design
direction was built, rejected, and rebuilt; features were added before their scope was
agreed.

This document exists to stop that. It records what the system **is**, what it **will
become** before the defence, and what it will **never** be, so that implementation can
proceed without further interviewing.

It is not a proposal to rebuild. The existing code is the baseline.

---

## GOAL

A web-based institutional repository that lets a reader **find and read past research**,
lets faculty and the library deposit work through a supervised workflow, and demonstrably
applies Dublin Core, PREMIS and METS to every deposit.

Success is measured at the capstone defence: a panel member can search the collection,
open a record, inspect its metadata in all three standards, and watch a deposit move from
faculty to library — without the system being staged for them.

**Primary user action:** find and read past research.

### The three objectives, and where each is evidenced

| # | Objective | Evidenced by |
|---|-----------|--------------|
| 1 | Upload, storage and management of theses, capstones and research papers | `submit.php` → `review.php` → published record; `records`, `record_files`, `submission_reviews` |
| 2 | Dublin Core, PREMIS and METS for discoverability and preservation | 15 `dc_*` columns; `premis_*` tables; `mets_*` tables; `mets.php` XML export; SHA-256 fixity audit |
| 3 | An interface that encourages self-archiving | One-form deposit with live completeness meter; role dashboards; notifications; messaging |

---

## SCOPE

### Already built and staying (baseline)

- Public discovery: home, browse with facets, record detail, metadata and preservation pages
- Deposit in a single transaction that writes DC + PREMIS + METS + SHA-256
- Mediated deposit: faculty deposit (their own work, or student work they supervised) → library review → publication. Students read and download; they do not deposit
- Fixity audit that recomputes every digest and writes a PREMIS `fixity check` event per file
- METS 1.12 XML export per record
- Notifications, student–adviser messaging with typing indicators
- Admin management: all records, people, roles, access levels, archive/restore
- Profile with avatar and password change
- Dark-first design with a light theme toggle

### New work agreed

1. **Seed ~25 sample records** across all 9 departments, several years, mixed workflow states
2. **Record page redesign** — the page a panel studies hardest
3. **Empty states and dead ends** — no screen that a hands-on tester can click into a void
4. **Full text requires sign-in** — change `canReadFullText()` so `open` records expose
   metadata publicly but gate the file behind a login

---

## NON-GOALS

Explicitly excluded. These will not be proposed again.

| Excluded | Reason |
|----------|--------|
| ~~AI chatbot assistant~~ | **Now in scope and built.** Answers from live database queries and a curated knowledge base; an optional language-model fallback exists but ships disabled. The ordering is deliberate: nothing about this repository is ever answered by a model, so it cannot misstate the system |
| Real email sending | Notifications stay in-app. SMTP from XAMPP is fragile and fails on the day |
| OAI-PMH, DOI minting, external harvesting | Cannot be demonstrated offline; large effort, no defence value |
| Full-text search inside PDFs | Requires an extraction library that is not installed |
| Public web hosting, HTTPS, domain | Runs on local XAMPP only |
| Migration of the old `htdocs/repository-system` | Superseded. Left untouched, along with its backup |
| Plagiarism checking, citation export beyond plain text, mobile app, multi-language | Never in scope |

---

## TECH STACK (pinned to what is installed)

| Layer | Version | Note |
|-------|---------|------|
| PHP | **8.2.12** | Uses `match`, ENUM columns, `never` return type |
| MariaDB | **10.4.32** | InnoDB, `utf8mb4` |
| Apache | **2.4.58** (Win64, XAMPP) | `BASE_URL = /sjp2cd-repository/` |
| Database driver | PDO MySQL | `ERRMODE_EXCEPTION`, `EMULATE_PREPARES = false` |
| Front end | Hand-written CSS + vanilla JS | No framework, no build step, no bundler |
| Fonts | Source Serif 4, Inter, JetBrains Mono | Google Fonts CDN, with full local fallback stacks |

**Available extensions:** `mbstring`, `fileinfo`, `pdo_mysql`, `pdo_sqlite`
**Not available:** `gd` (disabled in `php.ini`) — no server-side image processing. Any
image resizing is done ahead of time, not at runtime.

**Constraint:** no Composer, no npm, no CDN JavaScript. Everything must run offline on a
machine with only XAMPP.

> **Note on native prepared statements.** Because `EMULATE_PREPARES` is off, MySQL
> requires each named placeholder to appear **exactly once** per statement. Reusing
> `:me` twice raises `SQLSTATE[HY093]`. Use distinct names (`:me1`, `:me2`) — see
> `messages.php` for the pattern.

---

## FILE STRUCTURE

```
sjp2cd-repository/
├── index.php              Home — hero, objectives, featured records
├── browse.php             Faceted search: department, type, year, subject, author, sort
├── about.php              Deposit policy, the three objectives, links to the standards pages
├── suggest.php            JSON search suggestions (published + archived only)
├── record.php             Record detail — 5 tabs (Overview/DC/PREMIS/METS/Files)
├── submit.php             Deposit form — one transaction writes all three standards
├── mets.php               METS 1.12 XML export
├── download.php           Gated file delivery + PREMIS dissemination event
├── standards.php          Objective 2 explainer, driven by live counts
├── preservation.php       Public: how files are looked after + the last audit result
│                          Library staff only: format register, audit history, run timings
├── dashboard.php          Role-branching landing page
├── my-work.php            Depositor's own records + send/withdraw/delete
├── review.php             Adviser + library review queue and decisions
├── manage-records.php     Admin: access level, archive/restore, rebuild METS
├── manage-users.php       Admin: roles, departments, advisers, activation
├── profile.php            Details, avatar, password
├── notifications.php      In-app alerts
├── messages.php           Live chat UI
├── chat-api.php           JSON endpoint: poll / send / typing
├── login.php  register.php  logout.php
├── install.php            Creates DB, departments, PREMIS agents, 5 accounts
│                          ⚠ DROPS the database if re-run
├── config/
│   ├── config.php         Constants, timezone (Asia/Manila), workflow, access levels
│   └── database.php       PDO connection
├── includes/
│   ├── auth.php           Roles, session, canReadFullText, canEditRecord
│   ├── helpers.php        e(), url(), csrf, flash, notify, logActivity, mintIdentifier
│   └── metadata.php       ★ The Objective 2 engine — DC map, PREMIS, METS, fixity
├── templates/layout/      header/footer (public) + app_header/app_footer (signed in)
├── assets/
│   ├── css/design-system.css   Tokens, components, 3D depth, campus panel
│   ├── css/chat.css
│   ├── js/ui.js           Theme, reveal, tilt, parallax, tabs, counters
│   ├── js/icons.js        72 inline SVG icons
│   ├── js/chat.js         Polling chat client
│   ├── js/hero3d.js       Ambient hero background (lazy, capped, pausable)
│   ├── js/vendor/three.min.js   Three.js r128, MIT, vendored for offline use
│   └── img/               logo.svg, seal.png
├── sql/schema.sql
└── uploads/records/       Deposited files, named by the system
```

**New file this work adds:**
`sql/seed-sample-data.php` — generates the 25 sample records. Named so its purpose is
unmistakable to anyone reading the repository.

---

## DESIGN SYSTEM — locked

Dark-first. The dark theme is the design; light is a supported alternative reached only by
explicit choice, applied before first paint to avoid a flash.

### Colour

| Token | Dark (default) | Light | Contrast (dark / light) |
|-------|---------------|-------|------------------------|
| `--bg` | `#04050F` | `#F4F6FC` | — |
| `--surface` | `#0B0D22` | `#FFFFFF` | — |
| `--text` | `#EEF0FF` | `#0A0C1F` | 16.9:1 / 19.4:1 |
| `--text-muted` | `#A5A9CC` | `#4A5169` | 8.4:1 / 7.9:1 |
| `--primary` (text/icon) | `#7C87FF` | `#2B31B8` | 6.2:1 / 9.4:1 |
| `--primary-solid` (button fill) | `#4C56E8` | `#1A1D92` | white on it: 5.5:1 |
| `--accent` | `#FFE44D` | `#6B6B00` | 15.0:1 / 5.2:1 |
| `--border-input` | `#5560AB` | `#7F89AC` | 3.3:1 / 3.5:1 |

- Brand blue `#0F1175` and seal yellow `#FEFE00` are sampled from the official college seal.
- The seal yellow is **display-only on light** (1.07:1 on white) — light mode substitutes
  `--gold-800`. On dark it reaches 18.8:1 and carries the accent phrase.
- `--primary` and `--primary-solid` are separate tokens because one colour cannot serve
  both body text and a white-on-fill button accessibly.
- Input borders are a distinct token: a field's border is a control boundary and must
  clear 3:1 on its own.

### Type
`Source Serif 4` headings · `Inter` body · `JetBrains Mono` identifiers and digests.
Scale: 0.75 / 0.8125 / 0.9375 / 1 / 1.125 / 1.375 / 1.75 / 2.25 / 3 / 3.75 rem.

### Depth (the 3D system)
One perspective (`1100px`), one tilt ceiling (`5°`), light from above.

- Every panel carries an inner top highlight and bottom shadow (`--edge-top` / `--edge-bottom`)
- Buttons travel 2px on the Z axis when pressed; their shadow shortens with them
- Showcase and stat cards turn toward the pointer; their contents sit on separate Z planes
  (icon 42px, figure 28px, label 16px) so the interior parallaxes
- Hero: campus photo panel tilts, leans toward the cursor, caption floats 46px above it
- Content enters from `translateZ(-90px)` with a 9° X-rotation, staggered 70ms per card
- **All motion is `transform` and `opacity` only** — nothing can cause layout shift
- `prefers-reduced-motion` disables every effect; `hover: none` disables tilt on touch
- Entrance animations use `fill-mode: backwards` so the resting state is the visible one;
  content is never dependent on an animation having run

### Hero
A single centred column, max-width 820px, vertically centred in a full-viewport section:
eyebrow, serif headline with the yellow italic accent line, lede, search bar, CTAs, stats.
Typography, colour and search styling are unchanged from the rest of the system.

Behind it, three layers:

1. `.hero-bg` — a static indigo/violet gradient that is **always** painted. It is the
   reduced-motion fallback, the mobile fallback, the no-WebGL fallback, and what shows
   before the 3D bundle arrives. Nothing depends on JavaScript having run.
2. `.hero-canvas` — six large low-poly forms with baked vertex displacement, drifting and
   rotating on unrelated frequencies so the composition never returns to a previous state
   and has no visible loop point. Deep indigo and violet, with **one** faint yellow form and
   a yellow rim light as the only appearances of the seal colour. No cursor interaction, no
   scroll triggers.
3. `.hero-scrim` — a radial and linear scrim guaranteeing the headline never sits on a
   bright part of the animation. Legibility outranks the effect.

The animation is confined to the hero and does not continue behind the rest of the page.

**It refuses to run** when the reader prefers reduced motion, the viewport is under 768px,
WebGL is unavailable, or the device reports under 4 GB memory or fewer than 4 cores. It is
capped at 30fps, stops when the hero scrolls out of view or the tab is hidden, and is loaded
on `requestIdleCallback` after `load` so 590 KB never blocks first paint.

---

## DATA MODEL

18 tables. `utf8mb4`, InnoDB, foreign keys with `ON DELETE CASCADE` where a child cannot
outlive its parent.

### Objective 1 — workflow
- **`departments`** — 9 colleges (CCS, CED, CBA, CN, CAS, CENG, CPSY, CCRIM, CACC)
- **`users`** — `role` ENUM(student|faculty|admin), `adviser_id` self-FK, `is_active`
- **`records`** — the deposit. `status` ENUM(draft, submitted, under_review, revision,
  approved, published, rejected, archived); `access_level` ENUM(open, campus, restricted);
  `embargo_until`; `views`; `downloads`
- **`record_files`** — `file_use` ENUM(ARCHIVE|ACCESS|SUPPLEMENT), `checksum` VARCHAR(128),
  `checksum_algo` default `SHA-256`, absolute `storage_path`
- **`submission_reviews`** — `stage` ENUM(adviser|library), `decision` ENUM(approved|revision|rejected)

### Objective 2 — metadata
- **15 `dc_*` columns on `records`** — first-class indexed columns, never a serialized blob.
  FULLTEXT index on `(dc_title, dc_creator, dc_subject, dc_description)`
- **`premis_objects`**, **`premis_events`**, **`premis_agents`**, **`premis_rights`** — PREMIS 3.0's four entities
- **`mets_packages`**, **`mets_files`**, **`mets_divisions`** — a real structMap, not hardcoded XML
- **`fixity_audits`**, **`fixity_results`** — audit runs and per-file outcomes
  (`passed` | `failed` | `missing_file` | `no_digest`)

### Objective 3 — interface
- **`notifications`**, **`messages`**, **`typing_status`**, **`activity_log`**

### Dublin Core mapping

| Element | Column | Required |
|---------|--------|----------|
| dc.title | `dc_title` | ✔ |
| dc.creator | `dc_creator` | ✔ |
| dc.subject | `dc_subject` | ✔ |
| dc.description | `dc_description` | ✔ |
| dc.type | `dc_type` | ✔ |
| dc.language | `dc_language` | ✔ |
| dc.contributor | `dc_contributor` | |
| dc.publisher | `dc_publisher` | |
| dc.date | `dc_date_issued` | |
| dc.format | `dc_format` | |
| dc.identifier | `dc_identifier` | |
| dc.source | `dc_source` | |
| dc.rights | `dc_rights` | |
| dc.coverage | `dc_coverage` | |
| dc.relation | `dc_relation` | |

Identifiers are minted as `SJP2CD-YYYY-NNNN` at publication.

---

## ROUTES

| Route | Auth | Purpose |
|-------|------|---------|
| `index.php` | public | Home |
| `browse.php` | public | Search + facets (`q`, `type`, `dept`, `year`, `sort`, `page`) |
| `record.php?id=` | public metadata | Record detail, 5 tabs |
| `mets.php?id=` | public for published | METS XML |
| `download.php?id=` | **signed in** | File delivery + dissemination event |
| `standards.php`, `preservation.php` | public | Objective 2 explainers |
| `login.php`, `register.php`, `logout.php` | public | Auth |
| `dashboard.php`, `my-work.php`, `submit.php`, `profile.php`, `notifications.php`, `messages.php` | any signed in | — |
| `chat-api.php` | any signed in | JSON: poll / send / typing |
| `review.php` | faculty + admin | Review queue |
| `manage-records.php`, `manage-users.php` | admin | Library management |
| `preservation.php` (POST) | admin | Run fixity audit |

---

## USER FLOWS

**Discover (primary).** Visitor lands → searches or browses → narrows by department, type
or year → opens a record → reads metadata, abstract and keywords → *to download, signs in*
→ file served, `downloads` incremented, PREMIS `dissemination` event written.

**Deposit.** A faculty member signs in → `submit.php` → fills one form with a live completeness ring
→ uploads PDF/DOCX → one transaction writes the DC record, stores the file, computes
SHA-256, creates the PREMIS object, three events, a rights entry and a METS package →
routed to the library queue. Any failure rolls back the transaction *and* deletes the file.

Student work reaches the repository the same way every established repository handles it:
the supervising faculty member deposits it, naming the student in `dc.creator`. Deposit is
a permission (`canDeposit()`), not a consequence of having an account.

**Review.** Adviser opens the item (status flips `submitted` → `under_review`) → sees
fixity status, metadata completeness and the file → approves / requests revision / returns
→ PREMIS `validation` event + notifications to the student and the library.

**Publish.** Library opens an approved record → publishes → identifier minted, METS
rebuilt, PREMIS `publication` event, student notified, record public.

**Preserve.** Admin runs an audit → every stored file re-hashed and compared → results
written per file, a `fixity check` PREMIS event recorded per object, failures surfaced with
expected vs actual digests.

---

## ACCEPTANCE CRITERIA (EARS)

### Discovery
- WHEN a visitor submits the search form, THE SYSTEM SHALL return only `published` records
  whose title, creator, subject or description matches, and SHALL highlight the matched
  term in the results.
- WHEN a visitor selects a facet, THE SYSTEM SHALL apply it additively with existing facets
  and SHALL reset pagination to page 1.
- IF no record matches the active filters, THEN THE SYSTEM SHALL show an empty state naming
  the filters and offering to clear them, and SHALL NOT show a blank list.
- WHEN results exceed 10, THE SYSTEM SHALL paginate and SHALL state the range and total.

### Access control
- WHEN an anonymous visitor opens a published record, THE SYSTEM SHALL show full metadata,
  abstract and keywords.
- IF an anonymous visitor requests `download.php`, THEN THE SYSTEM SHALL redirect to login
  and SHALL return them to the record afterwards.
- IF a signed-in user requests a `restricted` record's file and is not its depositor,
  adviser, faculty or library staff, THEN THE SYSTEM SHALL refuse and explain why.
- WHILE a record's `embargo_until` is in the future, THE SYSTEM SHALL withhold the full text
  from everyone except its depositor and library staff.
- IF a record is not `published` or `archived`, THEN THE SYSTEM SHALL NOT list it in Browse.

### Deposit
- WHEN a depositor submits with any of the six required Dublin Core elements missing, THE
  SYSTEM SHALL reject the submission and SHALL mark each missing field individually.
- WHEN a valid deposit is submitted, THE SYSTEM SHALL, in one transaction, create the
  record, store the file, compute a SHA-256 digest, create one PREMIS object, at least three
  PREMIS events, one rights entry and one METS package.
- IF any step of a deposit fails, THEN THE SYSTEM SHALL roll back every database change and
  SHALL delete any file already written.
- IF a signed-in user without deposit permission requests `submit.php`, THEN THE SYSTEM
  SHALL refuse, explain that deposits are made by faculty and the library, and return them
  to their dashboard.
- THE SYSTEM SHALL NOT offer a deposit control to an account that cannot deposit.
- WHEN a deposit is submitted by faculty, THE SYSTEM SHALL route it to the library queue.

### Review and publication
- WHEN a reviewer opens a `submitted` record, THE SYSTEM SHALL set it to `under_review`.
- IF a reviewer requests revision or returns work without a comment, THEN THE SYSTEM SHALL
  refuse and SHALL ask for a reason.
- WHEN an adviser approves, THE SYSTEM SHALL notify the depositor and every active library
  account.
- WHEN library staff publish, THE SYSTEM SHALL mint `SJP2CD-YYYY-NNNN` if absent, set
  `published_at` and `published_by`, rebuild the METS package and write a `publication` event.
- IF a reviewer attempts to act on a record outside their queue, THEN THE SYSTEM SHALL refuse.

### Preservation
- WHEN an audit runs, THE SYSTEM SHALL recompute the digest of every stored file, record one
  `fixity_results` row per file, and write one `fixity check` PREMIS event per object.
- IF a stored file's digest no longer matches, THEN THE SYSTEM SHALL record `failed` and
  SHALL display the expected and actual digests side by side.
- IF a stored file is missing from disk, THEN THE SYSTEM SHALL record `missing_file` rather
  than reporting a pass.
- WHEN METS XML is requested for a published record, THE SYSTEM SHALL emit a well-formed
  document containing `metsHdr`, `dmdSec`, `amdSec`, `fileSec` and `structMap`, with
  `CHECKSUMTYPE="SHA-256"`.
- WHEN a file is downloaded, THE SYSTEM SHALL increment `downloads` and write a
  `dissemination` PREMIS event.

### Interface and accessibility
- WHILE `prefers-reduced-motion` is set, THE SYSTEM SHALL disable all tilt, parallax,
  entrance and drift animation, and SHALL keep every page fully readable.
- IF JavaScript does not run, THEN THE SYSTEM SHALL still display all page content.
- WHEN any page renders in either theme, THE SYSTEM SHALL keep body text at ≥ 4.5:1 and
  form-control borders at ≥ 3:1.
- WHEN a form fails validation, THE SYSTEM SHALL place each error beside its field and SHALL
  associate it with `aria-describedby`.
- IF a list, queue or collection is empty, THEN THE SYSTEM SHALL explain why and offer the
  next action.

### Hero background
- WHILE the hero is out of view or the tab is hidden, THE SYSTEM SHALL stop rendering the
  background animation.
- IF `prefers-reduced-motion` is set, the viewport is under 768px, or WebGL is
  unavailable, THEN THE SYSTEM SHALL render the static gradient and SHALL NOT load the 3D
  bundle.
- IF the 3D bundle fails to load, THEN THE SYSTEM SHALL keep the static gradient and SHALL
  NOT surface an error to the reader.
- THE SYSTEM SHALL cap the background at 30fps and SHALL NOT begin loading it before the
  page `load` event.
- THE SYSTEM SHALL keep a scrim between the animation and the headline so that hero text
  never renders over a bright area.

### Security
- WHEN any state-changing POST is received, THE SYSTEM SHALL verify a CSRF token and SHALL
  reject the request with HTTP 400 if it is absent or stale.
- WHEN a user signs in or changes password, THE SYSTEM SHALL regenerate the session id.
- THE SYSTEM SHALL store passwords only as `password_hash()` digests.
- THE SYSTEM SHALL parameterise every SQL statement; string-interpolated user input in SQL
  is a defect.

---

## CONSTRAINTS

1. **Offline.** No Composer, npm, or CDN JavaScript at runtime. Google Fonts may fail to
   load and every face has a local fallback. Third-party libraries are **vendored** into
   `assets/js/vendor/` — currently `three.min.js` (r128, 590 KB, MIT). Nothing is fetched
   from a CDN when the page loads.
2. **No GD.** No server-side image processing. Images are prepared ahead of time.
3. **XAMPP only.** `BASE_URL` is `/sjp2cd-repository/`; no HTTPS, no domain.
4. **Uploads:** PDF and DOCX only, 50 MB ceiling, stored under a system-controlled name.
5. **SHA-256 everywhere.** MD5 is not acceptable, including in anything displayed.
6. **`install.php` drops the database if re-run.** It must never run during a demo.
7. **Do not touch** `htdocs/repository-system` or `Documents/repo-backups/`.
8. **One month to the defence.** Anything not defensible on the day is deprioritised.

---

## WORK PLAN — priority order

**1. Seed 25 sample records.** Everything else is judged against a populated shelf. The
primary action is discovery, and discovery is untestable with two records — facets, sorting,
pagination and empty-state logic all behave differently at scale. This unblocks the other
two items, so it goes first.

**2. Record page redesign.** The one page where all three objectives meet: Dublin Core,
PREMIS and METS are all visible in its tabs. It is also the page a panel will study longest,
and currently the least designed page in the system.

**3. Empty states and dead ends.** Cheap, unglamorous insurance. If anyone drives the system
themselves this is what they hit first, and a dead end in front of a panel costs more than
it costs to fix now.

### Deliberately deferred

- **ERD and data dictionary** — to be produced when documentation is requested. They are
  generated from the live schema, so generating them later guarantees they match the final
  database.
- **Mobile layout** — pages are responsive but untested at small sizes. Matters only if the
  panel opens it on a phone or the paper claims mobile support. See Open Questions.

---

## VERIFICATION STEPS

Run after each change.

**1. Syntax**
```bash
for f in *.php includes/*.php config/*.php templates/layout/*.php; do php -l "$f"; done
node --check assets/js/ui.js && node --check assets/js/chat.js
```

**2. Every page, every role.** Sign in as student, faculty and admin; confirm each page
returns 200 for those permitted and 302 for those refused. Students must be refused
`review.php` **and `submit.php`**; students and faculty must be refused `manage-*.php`.

Last run — student: submit 302, review 302, manage-records 302 · faculty: submit 200,
review 200, manage-records 302 · admin: all 200. Anonymous reads index, browse, about,
standards, preservation, record and suggest; is redirected from submit and dashboard.

**3. Deposit → approve → publish.** Deposit as faculty, publish as
the library. Confirm the identifier is minted and PREMIS events accumulate.

**4. Metadata.** Fetch `mets.php?id=`, confirm well-formed, 15 DC elements, PREMIS events
present, `CHECKSUMTYPE="SHA-256"`.

**5. Fixity, including a real failure.** Run an audit (expect all pass). Append bytes to a
stored file, re-run, confirm it reports `failed` with expected vs actual. Restore the file
and re-run to confirm it passes again.

**6. Visual.** Open at 1280px and 375px, in both themes.

> ⚠ **Known trap.** The browser tool's viewport-resize has twice collapsed its pane to
> `0×0`, producing convincing but false reports of a blank page and broken scroll
> animations. **If a page appears blank, open a fresh tab before believing it.**

**7. Accessibility.** Enable reduced motion and confirm the site is still complete and still.
Disable JavaScript and confirm content still renders. Tab through a form and confirm focus is
visible and never trapped.

---

## DEFINITION OF DONE

- [ ] ~25 sample records across all 9 departments, several years, mixed workflow states
- [ ] Browse shows populated facet counts; pagination exercised across multiple pages
- [ ] Record page redesigned; all five tabs legible and complete
- [ ] No screen reachable in a signed-in session shows an unexplained empty area
- [ ] Anonymous visitors read metadata; downloads require sign-in and return the user to the record
- [ ] Every page returns 200 for permitted roles and 302 for refused roles, with no PHP notice or warning
- [ ] A full deposit → approve → publish cycle completes and mints an identifier
- [ ] METS validates as well-formed with SHA-256 for every published record
- [ ] A fixity audit detects a deliberately corrupted file and reports it correctly
- [ ] Both themes pass contrast; reduced motion fully disables animation
- [ ] Seeded content documented here and in a plainly-named seed script
- [ ] Starter passwords rotated, or the risk consciously accepted and noted

---

## SEEDED DATA — disclosure

The repository will contain approximately 25 **generated sample records**. They exist so
that search, facets, sorting, pagination and the review queue can be demonstrated on a
populated collection.

They are:

- generated by `sql/seed-sample-data.php`
- not real student submissions
- not attributed to real people
- not visually marked in the interface, because the site is meant to look like a working
  repository during the demonstration

This disclosure exists so the distinction between real and sample content is never in doubt.
The two genuine test records created during development (`SJP2CD-2026-0001` and
`SJP2CD-2026-0002`) are likewise demonstration data.

---

## OPEN QUESTIONS

1. **Mobile.** Does the paper claim responsive design, or will the panel use a phone? If
   either is true, small-screen testing moves from deferred to required.
2. **Starter passwords.** `Sjp2cd!2026` appears in plain text inside `install.php`. Rotate
   before the defence, or accept and be ready to explain it?
3. **Personal account.** `me123@gmail.com` (Kevin Lara, student) exists alongside the five
   seeded accounts. Keep it for the demo, or remove it for a clean roster?
4. **Sample authors.** Invented names, or real classmates? Invented is strongly preferred —
   real names attached to fabricated papers is a consent problem.
5. **Adviser and panel feedback.** None received yet. Anything they ask for outranks this
   document, which will be revised rather than worked around.

---

## DECISIONS — the restructure

Four things were true of the system that are no longer true. Each was a correction, and
each has a reason worth being able to state out loud.

**1. Students cannot deposit.** The college librarian's rule, and it matches Objective 3,
which asks for *faculty* self-archiving. Deposit is now mediated: faculty deposit their own
research and the student work they supervised, naming the student in `dc.creator`; the
library reviews everything. This is ordinary practice — Alabama requires faculty sponsorship
for undergraduate work, Washington requires faculty vetting, Tulane reviews every student
submission, and DSpace treats deposit as a per-collection permission rather than a user
right. A student account still matters: it is what opens a gated full text.

**2. The home page stopped describing the study.** "What this system sets out to do" and
the three objectives told a reader they were looking at somebody's school project. They
moved to `about.php`, one click away, where a panel will look for them anyway. MIT's DSpace
nav is *Communities & Collections · Browse by · Statistics · About* — the standards are in
the system, not advertised by it. The home page now leads with search, then browse by
programme, then the newest deposits.

**3. Staff instrumentation left the public pages.** The format register, audit history and
run timings are how the library steers preservation work; no public repository exposes them.
They are behind `canPublish()`. What a reader needs — that files are checked, and the result
of the last check — stays public.

**4. Export left the result rows.** METS on a search result is not something any repository
does; it lives inside the record. Its place was taken by something readers actually use:
APA, MLA and BibTeX citations, generated from the record.

### Two defects found while doing it

**Dead clicks.** `.result`, `.btn` and `.chip` all translated upward on hover, stacking to
6px. A control that rises out from under the pointer that just arrived loses hover, drops
back, regains it — a flicker — and because `mousedown` and `mouseup` then land on different
elements, the click never fires. Fixed with an invisible hit-area guard that spans the
distance travelled. This is what made "Open record" unclickable and the keyword tags flicker.

**Scripts were never cache-busted.** `design-system.css` and `assistant.js` carried
`?v=<mtime>`; `ui.js` and `icons.js` did not, on either layout. Any change to them could sit
invisible behind a browser cache. All four are versioned now. This is the same failure that
once made the login page look broken.

### Revising published work

A published work can be taken down to revise it: by its faculty depositor (**My deposits → Withdraw to revise**, reason required) or by the library (**Manage records → Withdraw for revision**). It leaves browse and suggestions, but its address shows a *temporarily withdrawn* notice with its identifier rather than breaking — a cited link must never 404. The depositor edits it in the deposit form (`submit.php?id=`), optionally replacing the file; the old file is kept and marked `superseded_at`, so METS and downloads use the current version while fixity audits still check both. Saving sends it to the library, which republishes it under the **same identifier**. Every step is a PREMIS event: withdrawal, metadata modification, ingest of the revised file, submission, publication.

While the library is reviewing a deposit, the depositor cannot edit it; they withdraw it to a draft first. The library can edit any record at any time.

**Fixed on the way:** `premisEvent()` accepted `'fail'`, which is not a value of the `outcome` ENUM; MariaDB outside strict mode stored an empty string. A detected fixity failure was being logged with no outcome. Outcomes are now normalised and anything else is refused; the one affected row was repaired.

### Manage records tabs

Figure 6 grouped the workflow into four tabs (All, Pending, Approved, Rejected). Two groups hid what the library needs to separate, so the tabs are now **All · Pending · Needs revision · Published · Archived · Returned**. *Approved* mixed live and archived work; *Rejected* mixed returned work with work only waiting on the author. **Figure 6 in the paper needs updating to match.**

### PDF only, scheduled maintenance, OAIS alignment

**PDF only.** Uploads must be PDF by extension *and* by content (`%PDF-` header and `application/pdf` from fileinfo), so a renamed Word file is refused. All 28 stored files were already PDF. **Paper change:** the prototype description says *"PDF or DOCX files up to 50MB"* — make it *"PDF files up to 50MB"*.

**`sql/maintenance.php`** runs the archive sweep, a full fixity audit and a verified backup, logging to `logs/maintenance.log`. Backups go to `BACKUP_PATH` (default `C:/sjp2cd-backups`) as a dated folder with `database.sql`, `uploads/` and `manifest.csv`; every manuscript copy is re-hashed and compared. A restore into a throwaway database matched the live one table for table. A verified backup writes a PREMIS `replication` event per record. Scheduled weekly through Windows Task Scheduler — **the task has to be created by the user; it was not created for them.** `BACKUP_PATH` on the same disk guards against mistakes, not disk failure; point it at a USB drive or synced folder for a real second location.

**OAIS.** The About page carries a written preservation policy. The system follows OAIS for ingest, archival storage, data management and access, and says plainly it is not certified. Suggested paper wording: *"while the system follows OAIS principles in ingest, archival storage, data management, and access, it does not implement advanced preservation features such as format migration, nor does it claim full OAIS compliance."*

### Adviser and panel

The deposit form asked for *"Adviser and panel"* in one box. It is now two fields, stored as `records.adviser` and `records.panel` (semicolon-separated). `dc_contributor` is no longer typed: it is built as adviser + panel, because Dublin Core has a single contributor element covering everyone credited who is not an author. The record page lists Adviser and Panel separately. All 29 existing values were single adviser names and were backfilled into `adviser`; no panel was guessed.

### Document types and citations

**Dissertation removed** from `DOC_TYPES` — the college does not award doctorates and no record used it. Types are now Thesis, Capstone Project, Research Paper, Faculty Research.

**Citations are APA 7, MLA, IEEE and BibTeX.** APA 7 puts the work type and institution together in brackets and names the repository as the source; IEEE puts initials first and ends with `[Online]. Available:`. Both abbreviate given names **only when the author is recorded as "Surname, Given"** — the comma is what makes the split reliable. A name typed "Given Surname" is left exactly as entered rather than guessed at, because "Dela Cruz" cannot be separated safely. This is the strongest argument for normalising `dc_creator` (still open below).

### Four roles, and how faculty is granted

`INSTITUTIONAL_DOMAIN` is `sjp2cd.edu.ph`. Registration reads the address and decides: a college address creates a **student**, anything else creates a **guest** (shown as *Visitor*). Nobody registers as faculty — a college registrant may tick a box asking for it, which records `faculty_requested_at` and notifies the library; the library grants the role in Manage people. Deposit rights therefore cannot be self-claimed, which was the hole: any student could previously register as faculty.

Login refuses a student/faculty/admin account whose email is not institutional, and `manage-users` refuses to grant a college role to a non-institutional address — otherwise it would create an account that cannot sign in. The two existing Gmail student accounts were converted to visitors.

**A visitor** reads everything public — search, browse, record pages, abstracts, keywords, citations — and downloads nothing: `canReadFullText()` returns false for guests before access level is considered. Deposit, My deposits and Messages are hidden from the sidebar and refuse the visitor directly.

Verified: Gmail registration → guest, download 302, deposit 302; college registration → student, download 200, deposit 302; library grants faculty → deposit 200; library cannot grant faculty to a Gmail account.

**Paper change:** the login description says users *"select their role as Admin, Faculty/Researcher, or Student"*. There is no role picker — the role comes from the account, and faculty is granted by the library.

### Proving the address belongs to you

Typing a college address is not holding one, and a college account buys the right to download the collection. So a **college account is created switched off** and the library confirms it in Manage people (**Confirm this account**), where pending accounts sort to the top with a badge; the library is notified at registration, with the student number if given. A **visitor account is open immediately** — it can only read what is already public.

Login tells a pending user why, but only after the password verifies; a wrong password still gets the generic message, so the page never confirms that an address is registered.

**Bug found doing this:** the final `$error = ...` in `login.php` was unconditional, so it overwrote both specific reasons (pending account, non-institutional college account) with the generic line. Now only set when no reason was given.

**Stronger option, not built:** Google Sign-In restricted to the `sjp2cd.edu.ph` hosted domain (`auth-google.php` is already stubbed and disabled). That proves ownership rather than asking the library to vouch, and needs a Google Cloud OAuth client the college must create.

### Google Sign-In (built, waiting on credentials)

Turns "the library vouches for you" into "Google proves it". `auth-google.php` exchanges
the code server-side, verifies the `id_token` with Google (this install has no JWT
library, so an unverified token would be worth nothing), checks `aud`, `email_verified`
and `state`, then checks the address against `INSTITUTIONAL_DOMAINS` — the same list
email-and-password uses, so Google cannot admit an address the other route refuses. The
`hd` request parameter is treated as a hint only, never as proof.

**It removes the manual gate.** A college account created through Google opens
immediately, and an account already waiting confirms itself on first Google sign-in —
that is precisely what the librarian was being asked to vouch for, proved directly.

To keep that from reopening accounts the library closed on purpose, `users.deactivated_at`
now records a deliberate close: set by **Deactivate**, cleared by **Confirm this account**.
Google auto-confirms only accounts where it is null. Manage people shows *Waiting to be
confirmed* or *Closed by the library* accordingly. Verified by deactivating and restoring
a seeded account.

`sql/check-google.php` checks credentials, the domain match and HTTPS reachability, and
prints the exact redirect URI. HTTPS to Google already works here with
`CURLOPT_SSL_VERIFYPEER` on, so no certificate bundle is needed.

**Untested end to end:** the OAuth round trip needs a Google client, which requires
signing in to a Google account — the user creates it. Everything around it is tested:
unconfigured, no button renders anywhere and `auth-google.php` redirects to login.

### Still open

- **Author names are recorded two ways.** 21 names are natural order ("Althea M. Ronquillo"),
  2 are inverted ("Ronquillo, Althea M."), so two people appear twice in the author facet and
  APA renders them inconsistently. The deposit form teaches the inverted form; the seed data
  does not follow it. Needs a one-off normalisation of `dc_creator`.
- Name disambiguation generally — two people who share a surname collide. Real repositories
  solve this with ORCID. Worth a sentence in the paper's limitations, not worth building.
