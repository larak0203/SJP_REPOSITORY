<?php
/* ============================================================================
   SJP2CD Institutional Repository — application configuration
   ============================================================================ */

declare(strict_types=1);

/* The college is in Davao City. Without this PHP falls back to whatever php.ini
   says, which disagrees with the times MySQL writes and makes every "2 hours
   ago" wrong. */
date_default_timezone_set('Asia/Manila');

session_start();

/* ---------------------------------------------------------------- paths ---- */
define('APP_NAME',    'SJP2CD Institutional Repository');
define('APP_SHORT',   'SJP2CD Repository');
define('BASE_URL',    '/sjp2cd-repository/');
define('ROOT_PATH',   dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads/records');
define('AVATAR_PATH', ROOT_PATH . '/uploads/avatars');

/* ---------------------------------------------------------------- rules ---- */
define('MAX_UPLOAD_BYTES', 50 * 1024 * 1024);          // 50 MB
/* PDF only. A PDF looks the same on every machine, now and in twenty years; a
   DOCX shifts with the software that opens it. Accepting only the stable format
   is how the system keeps its promise without doing format migration, which
   Scope and Limitation rules out. */
define('ALLOWED_EXTENSIONS', ['pdf']);

/* Where scheduled backups go. Point this at a second place -- a USB drive, a
   network share, or a folder synced to Google Drive -- so a copy survives if
   this computer does not. A folder on the same disk protects against mistakes,
   not against the disk failing. */
define('BACKUP_PATH', 'C:/sjp2cd-backups');
define('MYSQLDUMP_BIN', 'C:/xampp/mysql/bin/mysqldump.exe');
define('DIGEST_ALGO', 'sha256');                        // never MD5
define('DIGEST_LABEL', 'SHA-256');
define('METS_PROFILE', 'https://sjp2cd.edu.ph/mets/profiles/etd-v1');

/* Document types — mirrors the ENUM on records.dc_type */
const DOC_TYPES = ['Thesis', 'Capstone Project', 'Research Paper', 'Faculty Research'];

/* Scope and Limitation: 'Research documents older than five years will be
   automatically moved to an archive section.' Archived records keep their
   identifier, metadata and files, and stay reachable through the archive view —
   they simply leave the main catalogue listing. */
const ARCHIVE_AFTER_YEARS = 5;

/* Access levels and what each means to a reader */
const ACCESS_LEVELS = [
   'open'       => 'Open access — anyone may read and download',
   'campus'     => 'Campus only — full text limited to college accounts',
   'restricted' => 'Metadata only — abstract public, full text on request',
];

/* The workflow, in order. Used for progress display and validation. */
const WORKFLOW = [
   'draft'        => 'Draft',
   'submitted'    => 'Submitted',
   'under_review' => 'Under review',
   'revision'     => 'Revisions requested',
   'approved'     => 'Approved by adviser',
   'published'    => 'Published',
   'rejected'     => 'Returned',
   'archived'     => 'Archived',
];

/* -------------------------------------------- Assistant: optional LLM ----
   OFF until you paste a key below. Leave it empty and the assistant answers
   only from this database and its curated notes — which is the safe default
   and needs no internet.

   When a key is present, questions the assistant does NOT recognise are passed
   to a language model so it can hold a general conversation. Questions about
   the repository are still answered from live data first and never reach the
   model, so it cannot state something untrue about this system.

   This needs the internet and costs money per message. See ASSISTANT-LLM.md. */
define('ASSISTANT_LLM_KEY',   '');
define('ASSISTANT_LLM_URL',   'https://api.openai.com/v1/chat/completions');
define('ASSISTANT_LLM_MODEL', 'gpt-4o-mini');
define('ASSISTANT_LLM_ON', ASSISTANT_LLM_KEY !== '');

/* ---------------------------------------------------- Google Sign-In ----
   OFF until you paste a client id below. Leave it empty and the button never
   renders, so the sign-in page cannot show a control that does not work.

   This needs the internet. If the college wifi is down during your defence,
   Google Sign-In fails and email + password remains the working path — which
   is why it is an addition, never a replacement.

   To switch it on, see GOOGLE-SIGNIN.md. */
define('GOOGLE_CLIENT_ID',     '903602904919-etnplk2okc142togo1nqbmapg9qctvc7.apps.googleusercontent.com');   // e.g. 1234-abc.apps.googleusercontent.com
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_HOSTED_DOMAIN', 'sjp2cd.edu.ph');

/* A college account is one held on the college's own mail domain. Everyone
   else -- a Gmail address, an alumnus, a researcher from another school -- is
   a visitor: welcome to read the catalogue, not to open the files. */
/* The mail domains the college issues addresses on, and the role each one
   means. A school often runs more than one -- staff on one, students on
   another, and an older domain that still works -- so this is a list rather
   than a single name, and every address on it is a college address.

   Add or change a line here and the whole system follows: registration, the
   sign-in rule, the notice on the form, the chatbot's answer and the library's
   checks all read this.

   The first entry is the one shown to readers as the college's address. */
const INSTITUTIONAL_DOMAINS = [
   'sjp2cd.edu.ph' => 'student',
   /* Example, if staff hold addresses on their own domain:
       'faculty.sjp2cd.edu.ph' => 'faculty',   // still confirmed by the library */
];

/* Kept so older code and messages keep working. */
define('INSTITUTIONAL_DOMAIN', array_key_first(INSTITUTIONAL_DOMAINS));
define('GOOGLE_ENABLED', GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '');

require_once __DIR__ . '/database.php';
require_once ROOT_PATH . '/includes/helpers.php';
require_once ROOT_PATH . '/includes/auth.php';
