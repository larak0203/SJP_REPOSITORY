# Where things stand

Written so a new assistant, or a future you, can pick this up without the chat it came
from. Read `CLAUDE.md` first for how to work in this project, and `SPEC.md` for the full
record of decisions.

---

## The system in one paragraph

A working institutional repository. Visitors and the public search and read; college
accounts download; faculty deposit; the library reviews, publishes and preserves. Every
deposit writes Dublin Core, a PREMIS object with events, a METS package and a SHA-256
fingerprint. Published work older than five years moves to an archive automatically.
Sign-in is through the college Google account, with email and password as a fallback.

## Who can do what

| Role | How they get it | Can |
|---|---|---|
| **Visitor** (`guest`) | Registering with any non-college address | Search, read, see abstracts, copy citations, download METS. **No file downloads** |
| **Student** | A `@sjp2cd.edu.ph` address | Everything above, plus downloading files |
| **Faculty** | **Granted by the library.** Never self-claimed | Deposit their own work, and student work they supervised |
| **Library** (`admin`) | Granted by the library | Review, publish, withdraw, manage people and records, run audits |

Deposit is **mediated**: students do not deposit. The College Librarian's rule, and it
matches Objective 3, which speaks of *faculty* self-archiving. Student theses are
deposited by the supervising faculty member, who names the student as the author.

## Sign-in

- **Google is the front door.** Restricted to `sjp2cd.edu.ph`, verified server-side
  against the token, not against the request. Creates the account and opens it at once.
- **Email and password** stays for visitors, the library's seeded accounts, and as the
  path that still works with no internet. Keep it. Test it before the defence.
- A college account created through the **password** form waits for the library to
  confirm it, because typing an address proves nothing. Google proves it, so it skips
  the wait. A pending account confirms itself on first Google sign-in; one the library
  deliberately closed does not (`users.deactivated_at` tells them apart).

Setup and troubleshooting: `GOOGLE-SIGNIN.md`. Readiness check:
`C:/xampp/php/php.exe sql/check-google.php`.

## Accounts

Seeded accounts all use `Sjp2cd!2026` — **written in plain text in `install.php`, rotate
before the defence**.

`admin@sjp2cd.edu.ph` (library) · `villareal@sjp2cd.edu.ph`, `cortez@sjp2cd.edu.ph`
(faculty) · `ronquillo@`, `vasquez@` (students) · plus the owner's own accounts and two
Gmail visitor accounts.

Note: `kevin_lara@sjp2cd.edu.ph` was created by Google sign-in as a **student**, while
`kevin.admin@sjp2cd.edu.ph` is the **admin**. Decide whether to merge or promote.

---

## Open, waiting on a decision

- **"Request faculty access" for Google users.** *(a real gap)* Faculty and students share
  one address format, so nothing in the address identifies faculty. The library grants the
  role — but the request checkbox only exists on the password form, which faculty no
  longer use. A Google-created faculty member currently has **no way to ask**.
- **Faculty roster import**, if the librarian can produce a list of faculty emails. Makes
  faculty status come from the official roll rather than a click.
- **Author names are recorded two ways.** Most are natural order ("Althea M. Ronquillo"),
  a few inverted ("Ronquillo, Althea M."). Two people therefore appear twice in the author
  filter, and APA 7 and IEEE can only abbreviate given names when the name has a comma.
  Needs a one-off normalisation of `dc_creator`.
- **Keyword chips.** 97% of keywords belong to exactly one work, so clicking one usually
  returns the work you clicked from and looks broken. Suggested: show a count and only
  link when more than one work shares it.
- **Block downloads of a file that fails its integrity check.** Detection exists; the file
  is still served.
- **Delete accounts that were never confirmed**, so a squatted address can be freed.
- **OAI-PMH.** The paper's comparison table claims it; the system does not have it. Either
  build it (one new file, the Dublin Core is already there) or correct the paper.

## Waiting on the owner

- **Create the weekly maintenance task** (backup + integrity check + archive sweep):
  ```
  schtasks /Create /TN "SJP2CD Repository Maintenance" /TR '"C:\xampp\php\php.exe" "C:\xampp\htdocs\sjp2cd-repository\sql\maintenance.php"' /SC WEEKLY /D SUN /ST 20:00
  ```
  Until this exists, backups and integrity checks only happen when run by hand.
- **Point `BACKUP_PATH` at a second device.** It currently writes to the same disk, which
  guards against mistakes but not against the disk failing.
- **Usability testing.** Objective 3 claims the interface *encourages* participation.
  Timing a few faculty through one deposit each, with a short questionnaire, turns that
  from an argument into evidence. Scope and Limitation already promises this testing.
- **Rotate the starter passwords.**

## Paper edits still pending

See `PAPER-CHANGES.md` for detail. The ones that matter most:

| Where | Problem |
|---|---|
| Comparison table | Claims **OAI-PMH** and **full-text search**. The system has neither — a panelist can disprove both in seconds |
| EPrints review | Says the proposed system has "OAI-PMH compliance" |
| System modules | "indexes all content for full-text searching" — it searches metadata, not inside PDFs |
| Login description | "select their role as Admin, Faculty/Researcher, or Student" — there is no role picker, and choosing your own role would be a security hole |
| Deposit page | "PDF **or DOCX**" — PDF only now |
| Figure 6 | Tabs are now All, Pending, Needs revision, Published, Archived, Returned |
| Background | "faculty, **students**, and staff can submit" — students no longer deposit |
| Title page | Still dated May 2027 from the Capstone 1 revision |

---

## What was deliberately not built

Format migration and full OAIS certification — both named in Scope and Limitation as out
of scope, and both now consistent with what the system says about itself. Real email
sending, plagiarism checking, and public hosting are also out; hosting is
deployment-ready but awaits college IT.
