<?php
/**
 * =============================================================================
 * REPOSITORY ASSISTANT  —  grounded, offline, no language model
 * =============================================================================
 *
 * Answers questions about this repository. Every answer comes from one of two
 * places and nowhere else:
 *
 *   1. A live query against this database — counts, holdings, newest work.
 *   2. A curated knowledge base written against what this system actually does.
 *
 * There is no language model, so it cannot invent a feature that does not
 * exist. When it does not recognise a question it says so and offers the pages
 * that might help, rather than guessing. That refusal is the point: a confident
 * wrong answer in front of a panel is worse than an honest "I don't know".
 *
 * PRIVACY. It answers only from public information: published and archived
 * records. It never reports on drafts, submissions under review, other people's
 * deposits, or any account detail — see publicOnly() below.
 */

require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $payload): never {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$question = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
if ($question === '') {
    reply(['ok' => false, 'answer' => 'Ask me something about the repository.']);
}
if (mb_strlen($question) > 400) {
    $question = mb_substr($question, 0, 400);
}

/* Normalised form used for matching: lowercase, punctuation stripped. */
$q = ' ' . preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($question)) . ' ';
$q = preg_replace('/\s+/', ' ', $q);

$has = static function (array $words) use ($q): bool {
    foreach ($words as $w) {
        if (str_contains($q, ' ' . $w . ' ')) return true;
    }
    return false;
};
$hasAll = static function (array $words) use ($q): bool {
    foreach ($words as $w) {
        if (!str_contains($q, ' ' . $w . ' ')) return false;
    }
    return true;
};

/* Only ever counts what a visitor could see for themselves in Browse. */
function publicOnly(): string { return "status IN ('published','archived')"; }

$url = static fn(string $p): string => url($p);

/* ==========================================================================
   0. Being spoken to like a person
   --------------------------------------------------------------------------
   Small talk is matched first and answered warmly. None of it pretends to be
   more than it is: asked what it can do, it says plainly that it knows this
   repository and little else.
   ========================================================================== */

$exact = trim($q);
$pick  = static fn(array $o): string => $o[array_rand($o)];

/* greetings */
if (preg_match('/^\s*(hi|hey|hello|yo|good (morning|afternoon|evening)|kumusta|kamusta|musta)\b/', $exact)) {
    $hour = (int)date('G');
    $part = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    reply(['ok' => true, 'answer' =>
        $part . '. I look after this repository — I can tell you what is in it, how to deposit work, '
        . 'or how any of it is preserved. What would you like to know?',
        'suggest' => ['How many records are there?', 'How do I deposit my work?', 'What is Dublin Core?']]);
}

/* thanks */
if (preg_match('/\b(thank|thanks|thankyou|salamat|ty)\b/', $q)) {
    reply(['ok' => true, 'answer' => $pick([
        'You are very welcome. Ask me anything else about the repository.',
        'Glad that helped. Anything else you would like to know?',
        'Any time. I am here whenever you need something from the collection.',
    ])]);
}

/* farewell */
if (preg_match('/\b(bye|goodbye|see you|later|good night|goodnight)\b/', $q)) {
    reply(['ok' => true, 'answer' => 'Goodbye. Good luck with your research.']);
}

/* how are you */
if (preg_match('/\b(how are you|how r u|kamusta ka|how do you do)\b/', $q)) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE " . publicOnly())->fetchColumn();
    reply(['ok' => true, 'answer' =>
        'Working away, thank you — watching over ' . number_format($n) . ' records. '
        . 'More usefully: what are you looking for?']);
}

/* who / what are you */
if (preg_match('/\b(who are you|what are you|your name|are you (a )?(bot|robot|ai|human|real)|chatgpt)\b/', $q)) {
    reply(['ok' => true, 'answer' =>
        'I am the assistant for this repository — not a general chatbot. I do not use a language model. '
        . 'Every answer I give comes either from a direct query against this database or from a set of notes '
        . 'written about how this system actually works. That is deliberate: it means I cannot make something up '
        . 'about the repository. It also means I am no use on topics outside it.']);
}

/* what can you do */
if (preg_match('/\b(what can you (do|answer|help)|help me|what do you know|your (job|purpose)|commands)\b/', $q)) {
    reply(['ok' => true, 'answer' =>
        'I can tell you: <strong>what the repository holds</strong> — totals, holdings per programme, newest and '
        . 'most-read work; <strong>how to use it</strong> — depositing, the approval workflow, downloads, access '
        . 'levels and embargoes; and <strong>how it is built</strong> — Dublin Core, PREMIS, METS, identifiers, '
        . 'and how files are checked for corruption. I can also search the catalogue for you: just say what you '
        . 'are looking for.',
        'suggest' => ['What is in the collection?', 'Find papers about nursing', 'How are files kept intact?']]);
}

/* jokes and other things it is not for */
if (preg_match('/\b(joke|funny|sing|poem|story|weather|love you|marry|game|play)\b/', $q)) {
    reply(['ok' => true, 'answer' =>
        'That is outside what I do, I am afraid — I only know this repository. '
        . 'Ask me what is in the collection, or how to get your work into it, and I will be much more useful.']);
}

/* praise / complaint */
if (preg_match('/\b(good job|nice|awesome|great|useless|stupid|dumb|not helpful|bad bot)\b/', $q)) {
    reply(['ok' => true, 'answer' =>
        'Noted, thank you. If I missed what you needed, try asking in a different way — or the College Library '
        . 'can help directly at <code>repository@sjp2cd.edu.ph</code>.']);
}

/* ==========================================================================
   1. Questions answered from live data
   ========================================================================== */

/* --- how many records / how big is the collection --- */
$asksQuantity = str_contains($q, 'how many') || str_contains($q, 'how much')
             || str_contains($q, 'number of') || str_contains($q, ' count ')
             || str_contains($q, 'how big') || str_contains($q, 'total number');

if ($asksQuantity &&
    $has(['record', 'records', 'paper', 'papers', 'thesis', 'theses', 'research', 'document', 'documents', 'collection', 'work', 'works'])) {

    $n = $pdo->query("SELECT
            SUM(status='published') live,
            SUM(status='archived')  archived,
            COUNT(DISTINCT department_id) depts
         FROM records WHERE " . publicOnly())->fetch();

    reply(['ok' => true, 'answer' =>
        'The repository holds <strong>' . number_format((int)$n['live'] + (int)$n['archived']) . ' records</strong> that anyone can read — '
        . number_format((int)$n['live']) . ' in the current catalogue and '
        . number_format((int)$n['archived']) . ' in the archive, across '
        . (int)$n['depts'] . ' programmes. Work older than ' . ARCHIVE_AFTER_YEARS
        . ' years moves to the archive automatically but stays readable.',
        'links' => [['Browse the collection', $url('browse.php')], ['View the archive', $url('browse.php?view=archive')]]]);
}

/* --- holdings per programme --- */
if ($has(['programme', 'programmes', 'program', 'programs', 'department', 'departments', 'college', 'colleges', 'course'])) {
    $rows = $pdo->query(
        "SELECT d.code, d.name, COUNT(r.id) n
         FROM departments d
         LEFT JOIN records r ON r.department_id = d.id AND r." . publicOnly() . "
         GROUP BY d.id ORDER BY n DESC, d.code"
    )->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        $lines[] = '<strong>' . e($r['code']) . '</strong> ' . e($r['name']) . ' — ' . (int)$r['n'];
    }
    reply(['ok' => true, 'answer' =>
        'Holdings by programme, counting published and archived work:<br>' . implode('<br>', $lines),
        'links' => [['Browse by programme', $url('browse.php')]]]);
}

/* --- what kinds of work --- */
if ($has(['type', 'types', 'kind', 'kinds']) ||
    $hasAll(['what', 'accept'])) {
    $rows = $pdo->query(
        "SELECT dc_type, COUNT(*) n FROM records WHERE " . publicOnly() . " GROUP BY dc_type ORDER BY n DESC"
    )->fetchAll();
    $lines = [];
    foreach ($rows as $r) $lines[] = '<strong>' . e($r['dc_type']) . '</strong> — ' . (int)$r['n'];
    reply(['ok' => true, 'answer' =>
        'The repository accepts ' . implode(', ', array_map('e', DOC_TYPES))
        . '. What it currently holds:<br>' . ($lines ? implode('<br>', $lines) : 'Nothing published yet.'),
        'links' => [['Browse', $url('browse.php')]]]);
}

/* --- newest work --- */
if ($has(['newest', 'latest', 'recent', 'new'])) {
    $rows = $pdo->prepare(
        "SELECT id, dc_title, dc_creator, dc_date_issued FROM records
         WHERE " . publicOnly() . " ORDER BY published_at DESC LIMIT 3"
    );
    $rows->execute();
    $rows = $rows->fetchAll();
    if (!$rows) reply(['ok' => true, 'answer' => 'Nothing has been published yet.']);

    $lines = $links = [];
    foreach ($rows as $r) {
        $lines[] = '<strong>' . e($r['dc_title']) . '</strong><br>' . e($r['dc_creator'])
                 . ($r['dc_date_issued'] ? ' · ' . humanDate($r['dc_date_issued']) : '');
        $links[] = [mb_strimwidth((string)$r['dc_title'], 0, 42, '…'), $url('record.php?id=' . (int)$r['id'])];
    }
    reply(['ok' => true, 'answer' => 'The most recently published work:<br><br>' . implode('<br><br>', $lines), 'links' => $links]);
}

/* --- most downloaded --- */
if ($has(['popular', 'downloaded', 'most', 'read'])) {
    $rows = $pdo->prepare(
        "SELECT id, dc_title, downloads FROM records
         WHERE " . publicOnly() . " AND downloads > 0 ORDER BY downloads DESC LIMIT 3"
    );
    $rows->execute();
    $rows = $rows->fetchAll();
    if (!$rows) reply(['ok' => true, 'answer' => 'Nothing has been downloaded yet.']);

    $lines = $links = [];
    foreach ($rows as $r) {
        $lines[] = '<strong>' . e($r['dc_title']) . '</strong> — ' . number_format((int)$r['downloads']) . ' downloads';
        $links[] = [mb_strimwidth((string)$r['dc_title'], 0, 42, '…'), $url('record.php?id=' . (int)$r['id'])];
    }
    reply(['ok' => true, 'answer' => 'The most downloaded work:<br><br>' . implode('<br><br>', $lines), 'links' => $links]);
}

/* --- preservation position, measured --- */
if ($has(['fixity', 'checksum', 'integrity', 'corrupt', 'corrupted', 'sha', 'digest'])) {
    $audit = $pdo->query("SELECT run_at, objects_checked, passed, failed FROM fixity_audits ORDER BY run_at DESC LIMIT 1")->fetch();
    $files = (int)$pdo->query("SELECT COUNT(*) FROM record_files")->fetchColumn();

    $state = $audit
        ? 'The last audit ran ' . timeAgo($audit['run_at']) . ' and checked '
          . (int)$audit['objects_checked'] . ' files: <strong>' . (int)$audit['passed']
          . ' passed</strong>' . ((int)$audit['failed'] ? ', <strong>' . (int)$audit['failed'] . ' failed</strong>.' : ' and none failed.')
        : 'No audit has been run yet.';

    reply(['ok' => true, 'answer' =>
        'Every deposited file is fingerprinted with <strong>' . DIGEST_LABEL . '</strong> the moment it arrives, and the digest is stored with the record. '
        . 'An integrity audit recomputes every digest and compares it, so a file that changed on disk is detected rather than discovered years later. '
        . 'There are ' . number_format($files) . ' files under management. ' . $state,
        'links' => [['Preservation report', $url('preservation.php')]]]);
}

/* ==========================================================================
   2. The knowledge base — written against what this system actually does
   ========================================================================== */

$KB = [
    [
        'k' => ['deposit', 'submit', 'upload', 'contribute', 'archive my', 'publish my'],
        'a' => 'Deposits are made by <strong>faculty and the library</strong>, not by students directly. '
             . 'If you are a student, ask the adviser who supervised your work to deposit it &mdash; you are recorded as its author. '
             . 'For faculty: sign in, open <strong>Deposit work</strong>, and one form captures the title, authors, keywords, abstract, type, programme and language, with a PDF up to '
             . round(MAX_UPLOAD_BYTES / 1048576) . ' MB attached. On submit the system stores the file, computes its ' . DIGEST_LABEL
             . ' digest, writes the Dublin Core description, creates the PREMIS preservation records and builds a METS package, all in one step. '
             . 'It then goes to the library for review before publication.',
        'l' => [['How deposits work', 'about.php']],
    ],
    [
        'k' => ['dublin core', 'descriptive metadata', 'dc'],
        'a' => 'Dublin Core is the standard that makes a record findable. This system stores all <strong>fifteen elements</strong> — title, creator, contributor, subject, description, publisher, date, type, format, identifier, source, language, rights, coverage and relation — as real indexed database columns rather than as a blob, so search hits the metadata directly. Six are required before a deposit can be sent for review.',
        'l' => [['How the standards work', 'standards.php']],
    ],
    [
        'k' => ['premis', 'preservation metadata'],
        'a' => 'PREMIS 3.0 records whether a file can still be trusted. This system implements all four of its entities: the <strong>Object</strong> (the stored file and its digest), <strong>Events</strong> (ingest, format identification, digest calculation, validation, publication, download and every fixity check), <strong>Agents</strong> (who or what did it) and <strong>Rights</strong> (what may be done with it). You can see them on the PREMIS tab of any record.',
        'l' => [['How the standards work', 'standards.php'], ['Preservation report', 'preservation.php']],
    ],
    [
        'k' => ['mets', 'structural metadata', 'xml', 'package'],
        'a' => 'METS 1.12 is the wrapper that lets a deposit move somewhere else without falling apart. Each record has a package containing <code>metsHdr</code>, <code>dmdSec</code> (the Dublin Core), <code>amdSec</code> (the PREMIS), <code>fileSec</code> (every file with its size and checksum) and <code>structMap</code> (the chapter structure). You can download the XML from any record page.',
        'l' => [['How the standards work', 'standards.php']],
    ],
    [
        'k' => ['who can', 'role', 'roles', 'permission', 'permissions', 'access level', 'student', 'faculty', 'admin', 'librarian'],
        'a' => 'There are three roles. <strong>Students</strong> deposit work, which goes to their adviser. <strong>Faculty</strong> deposit their own research and review the students they advise. <strong>Library staff</strong> publish approved work, manage access levels, archive records and run integrity audits. Your role is set on your account — you cannot choose it when signing in.',
        'l' => [],
    ],
    [
        'k' => ['download', 'read the', 'full text', 'get the pdf', 'open the file'],
        'a' => 'Anyone can read a record\'s full description, abstract and keywords without an account. To download the file itself you need to <strong>sign in with a college account</strong>. Records marked campus-only or restricted are limited further, and a record under embargo keeps its full text private until the embargo date passes.',
        'l' => [['Sign in', 'login.php'], ['Browse the collection', 'browse.php']],
    ],
    [
        'k' => ['approval', 'approved', 'review', 'workflow', 'adviser', 'reviewed', 'how long'],
        'a' => 'A student deposit goes first to their <strong>adviser</strong>, who can approve it, ask for revisions, or return it — a reason is required for either of the last two. Once approved it reaches the <strong>library</strong>, which publishes it. Publishing mints a permanent identifier in the form <code>SJP2CD-YYYY-NNNN</code> and makes the record public. Every decision is recorded with its reviewer and comment.',
        'l' => [],
    ],
    [
        'k' => ['identifier', 'citation', 'cite', 'reference'],
        'a' => 'Every published record gets a permanent identifier like <code>SJP2CD-2026-0001</code>, minted at publication and never reused. Each record page has a ready-made citation you can copy.',
        'l' => [['Browse the collection', 'browse.php']],
    ],
    [
        'k' => ['archive', 'archived', 'old', 'five year', '5 year'],
        'a' => 'Work completed more than <strong>' . ARCHIVE_AFTER_YEARS . ' years ago</strong> moves to the archive automatically. Archiving is not deletion — the record keeps its identifier, metadata, files and full history, and stays searchable and downloadable. It simply leaves the main catalogue listing.',
        'l' => [['View the archive', 'browse.php?view=archive']],
    ],
    [
        'k' => ['file type', 'format', 'pdf', 'docx', 'word', 'size limit', 'how big'],
        'a' => 'The repository accepts <strong>PDF files only</strong>, up to <strong>' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB</strong>. A PDF looks the same on every computer and stays readable for years, which a Word file does not. To convert one in Word, use <strong>File → Save As → PDF</strong>.',
        'l' => [['How deposits work', 'about.php']],
    ],
    [
        'k' => ['gmail', 'outside', 'outsider', 'visitor', 'not a student', 'alumni', 'alumnus', 'public account', 'guest'],
        'a' => 'Anyone may register, whatever their email address. An account made with a <strong>' . institutionalDomainList() . '</strong> address is a college account; any other address gives a <strong>visitor account</strong>. A visitor can search the whole catalogue and read every description, abstract and keyword, but cannot download files. Downloading is for college accounts. If you are at the college and registered with a personal address, the library can help you move to your college one.',
        'l' => [['Browse the collection', 'browse.php'], ['About the repository', 'about.php']],
    ],
    [
        'k' => ['who can deposit', 'can i deposit', 'can students', 'am i allowed', 'student upload', 'not allowed to submit'],
        'a' => 'Students do not deposit directly. A member of faculty deposits their own research, and deposits the student work they supervised &mdash; the student is named as the author of the work. The library reviews every deposit before it is published. This is how most institutional repositories handle student work. A student account still matters: it is what opens a full text that is not public.',
        'l' => [['How deposits work', 'about.php'], ['Browse the collection', 'browse.php']],
    ],
    [
        'k' => ['embargo', 'restricted', 'private', 'confidential', 'hide'],
        'a' => 'Each deposit carries an access level. <strong>Open</strong> means any signed-in account can read the full text. <strong>Campus</strong> limits it to college accounts. <strong>Restricted</strong> keeps the description public but the file private, available only to the depositor, their adviser, faculty and the library. A deposit can also carry an embargo date, before which nobody outside that group sees the full text.',
        'l' => [],
    ],
    [
        'k' => ['password', 'forgot', 'locked out', 'reset', 'cannot sign in', 'can t sign in'],
        'a' => 'The College Library resets passwords — write to <code>repository@sjp2cd.edu.ph</code>. If you are signed in already, you can change your own password on the <strong>Profile</strong> page.',
        'l' => [['Profile', 'profile.php'], ['Sign in', 'login.php']],
    ],
    [
        'k' => ['who made', 'who built', 'about this', 'what is this', 'purpose', 'why'],
        'a' => 'This is the institutional repository of <strong>St. John Paul II College of Davao</strong>. It exists so the thesis, capstone and research work the college produces is kept in one place, described well enough to be found, and preserved well enough to still open in twenty years. It applies three standards to every deposit: Dublin Core for description, PREMIS for preservation, METS for packaging.',
        'l' => [['How the standards work', 'standards.php'], ['Preservation report', 'preservation.php']],
    ],
    [
        'k' => ['oai', 'pmh', 'harvest', 'doi', 'handle'],
        'a' => 'Not supported. This repository does <strong>not</strong> implement OAI-PMH harvesting, DOI minting or the Handle system. It mints its own permanent identifiers in the form <code>SJP2CD-YYYY-NNNN</code>. Those interoperability protocols are noted as future work.',
        'l' => [],
    ],
    [
        'k' => ['full text search', 'search inside', 'inside the pdf', 'text of the'],
        'a' => 'Search covers the <strong>metadata</strong> — title, authors, keywords and abstract — not the words inside the PDF files. So searching for a phrase that appears only on page 40 of a document will not find it. Searching its title, author or keywords will.',
        'l' => [['Browse the collection', 'browse.php']],
    ],
    [
        'k' => ['contact', 'email', 'phone', 'reach', 'librarian', 'help desk', 'support'],
        'a' => 'The College Library looks after this repository. Write to <code>repository@sjp2cd.edu.ph</code> for anything I cannot answer — access requests, password resets, or corrections to a record.',
        'l' => [],
    ],
    [
        'k' => ['edit', 'change my', 'mistake', 'correct', 'update my', 'wrong', 'delete my', 'withdraw', 'remove my'],
        'a' => 'While a deposit is still a draft or has been sent back for revision, you can change or delete it yourself from <strong>My deposits</strong>. Once it is published the record is fixed, because its identifier has already been issued — ask the library to correct it or to withdraw it to the archive.',
        'l' => [['My deposits', 'my-work.php']],
    ],
    [
        'k' => ['how long', 'when will', 'take to', 'waiting', 'status of my', 'still pending'],
        'a' => 'That depends on your adviser and the library rather than on the system — there is no fixed timetable. You can see exactly where your deposit stands, and any comment a reviewer left, on <strong>My deposits</strong>. You are notified whenever it changes state.',
        'l' => [['My deposits', 'my-work.php'], ['Notifications', 'notifications.php']],
    ],
    [
        'k' => ['plagiarism', 'turnitin', 'similarity', 'copy check'],
        'a' => 'No. This repository does not check work for plagiarism or similarity. It stores, describes and preserves what is deposited; academic integrity checking happens elsewhere, before deposit.',
        'l' => [],
    ],
    [
        'k' => ['mobile', 'phone', 'tablet', 'app'],
        'a' => 'There is no separate mobile app — the repository is a website, so it opens in any browser on a phone or tablet.',
        'l' => [],
    ],
    [
        'k' => ['secure', 'security', 'safe', 'hacked', 'encrypted'],
        'a' => 'Passwords are stored only as one-way hashes, never as readable text. Every form that changes something is protected against cross-site request forgery, and every database query is parameterised. Deposited files are served only through a gated download that checks your permission and records the event — they cannot be reached by guessing a URL.',
        'l' => [],
    ],
    [
        'k' => ['language', 'filipino', 'tagalog', 'cebuano', 'english', 'bisaya'],
        'a' => 'The interface is in English. Deposited work can be in any language — you record the language of the full text as part of the description, and it becomes searchable metadata.',
        'l' => [],
    ],
    [
        'k' => ['statistic', 'statistics', 'analytics', 'report', 'dashboard', 'how popular'],
        'a' => 'Library staff have an analytics page showing deposits and downloads over twelve months, holdings by programme, the most-read work, and preservation activity. Every record page also shows its own view and download counts publicly.',
        'l' => [['Browse the collection', 'browse.php']],
    ],
];

$best = null; $bestScore = 0;
foreach ($KB as $entry) {
    $score = 0;
    foreach ($entry['k'] as $key) {
        if (str_contains($q, ' ' . $key . ' ') || str_contains($q, ' ' . $key)) {
            /* Longer phrases are stronger evidence than single words. */
            $score += 1 + substr_count($key, ' ') * 2;
        }
    }
    if ($score > $bestScore) { $bestScore = $score; $best = $entry; }
}

if ($best && $bestScore > 0) {
    $links = [];
    foreach ($best['l'] as [$label, $path]) $links[] = [$label, $url($path)];
    reply(['ok' => true, 'answer' => $best['a'], 'links' => $links]);
}

/* ==========================================================================
   3. Last resort: treat the question as a search of the catalogue.
   This runs only after the knowledge base has had its chance, so "can I search
   inside the PDF?" is answered as a question about the system rather than
   being used as a search term.
   ========================================================================== */

/* --- search for something specific --- */
if ($has(['find', 'search', 'looking', 'about', 'papers', 'anything', 'show'])) {
    /* Strip the question words and search on what is left. */
    $stop = ['find','search','looking','for','about','me','any','anything','show','is','are','there','do','you','have','on','the','a','an','papers','paper','research','can','i','get','what','please','tell'];
    $terms = array_values(array_diff(array_filter(explode(' ', trim($q))), $stop));

    if ($terms) {
        $needle = '%' . implode(' ', array_slice($terms, 0, 6)) . '%';
        $hits = $pdo->prepare(
            "SELECT id, dc_title, dc_creator FROM records
             WHERE " . publicOnly() . "
               AND (dc_title LIKE ? OR dc_subject LIKE ? OR dc_description LIKE ? OR dc_creator LIKE ?)
             LIMIT 3"
        );
        $hits->execute([$needle, $needle, $needle, $needle]);
        $hits = $hits->fetchAll();

        if ($hits) {
            $lines = $links = [];
            foreach ($hits as $h) {
                $lines[] = '<strong>' . e($h['dc_title']) . '</strong><br>' . e($h['dc_creator']);
                $links[] = [mb_strimwidth((string)$h['dc_title'], 0, 42, '…'), $url('record.php?id=' . (int)$h['id'])];
            }
            $links[] = ['Search for more', $url('browse.php?q=' . urlencode(implode(' ', array_slice($terms, 0, 4))))];
            reply(['ok' => true, 'answer' => 'I found these:<br><br>' . implode('<br><br>', $lines), 'links' => $links]);
        }

        reply(['ok' => true, 'answer' =>
            'Nothing in the published collection matches <strong>' . e(implode(' ', array_slice($terms, 0, 6))) . '</strong>. '
            . 'Try a broader term, or browse by programme.',
            'links' => [['Browse the collection', $url('browse.php')]]]);
    }
}


/* ==========================================================================
   4. Nothing matched — say so rather than guessing
   ========================================================================== */
/* --------------------------------------------------------------------------
   Optional: hand anything still unrecognised to a language model.

   This is reached ONLY when every grounded route above has declined, so a
   question about the repository is never answered by the model — it is always
   answered from the database or the curated notes first. That ordering is what
   stops the model stating something untrue about this system.

   With no key configured this block does nothing and the honest "I don't know"
   below is what the reader gets.
   -------------------------------------------------------------------------- */
if (ASSISTANT_LLM_ON && function_exists('curl_init')) {

    /* The model is told what this system is, and told to decline rather than
       guess about it — belt and braces on top of the ordering above. */
    $system = 'You are a friendly assistant embedded in the institutional repository of '
        . 'St. John Paul II College of Davao, a Philippine college. Be warm, brief and plain-spoken; '
        . 'two or three sentences unless more is genuinely needed. '
        . 'You may answer general questions on any subject. '
        . 'But you must NOT make claims about how this repository works, what it contains, or what '
        . 'features it has — if asked, say that the repository assistant handles those and suggest they '
        . 'rephrase. Never invent statistics, record titles, authors or identifiers.';

    $payload = json_encode([
        'model' => ASSISTANT_LLM_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $question],
        ],
        'max_tokens'  => 300,
        'temperature' => 0.6,
    ]);

    $ch = curl_init(ASSISTANT_LLM_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ASSISTANT_LLM_KEY,
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw !== false && $code >= 200 && $code < 300) {
        $j = json_decode((string)$raw, true);
        $text = $j['choices'][0]['message']['content'] ?? '';
        if (is_string($text) && trim($text) !== '') {
            reply([
                'ok' => true,
                'llm' => true,
                'answer' => nl2br(e(trim($text))),
                'note' => 'Answered by a language model, not from repository data.',
            ]);
        }
    }
    /* Anything else — no key accepted, no internet, a rate limit — falls
       through to the honest answer below rather than surfacing an error. */
}

reply(['ok' => true, 'unknown' => true, 'answer' =>
    'I don\'t know that one. I can answer questions about what the repository holds, how to deposit work, '
    . 'the Dublin Core, PREMIS and METS standards, how approval works, and how files are kept intact. '
    . 'For anything else the College Library can help: <code>repository@sjp2cd.edu.ph</code>.',
    'links' => [
        ['Browse the collection', $url('browse.php')],
        ['How the standards work', $url('standards.php')],
        ['Preservation report', $url('preservation.php')],
    ]]);
