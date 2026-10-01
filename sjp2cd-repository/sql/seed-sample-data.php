<?php
/**
 * =============================================================================
 * SAMPLE DATA GENERATOR  —  NOT REAL STUDENT WORK
 * =============================================================================
 *
 * Creates ~25 demonstration records so that search, facets, sorting, pagination
 * and the review queue can be shown on a populated collection.
 *
 * Everything it creates is invented:
 *   · the titles and abstracts are written for this script
 *   · the author names are invented and belong to no one
 *   · the PDFs are generated placeholders, one page, stating what they are
 *
 * It writes through exactly the same path a genuine deposit takes — the same
 * columns, the same PREMIS objects and events, the same METS packages and the
 * same SHA-256 digests — so seeded records are structurally identical to real
 * ones. That is the point: the metadata demonstration has to be honest.
 *
 * RE-RUNNABLE. Every id it creates is written to sql/seeded-ids.json. Running it
 * again deletes exactly those records and their files first, and nothing else.
 * It never touches the two genuine test records or any real deposit.
 *
 *   CLI      php sql/seed-sample-data.php
 *   Browser  /sjp2cd-repository/sql/seed-sample-data.php?confirm=yes
 */

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['confirm'] ?? '') !== 'yes') {
        exit("Add ?confirm=yes to run the sample data generator.\n"
           . "It creates ~25 demonstration records and removes any it created before.\n");
    }
}
$out = static function (string $line): void { echo $line . "\n"; @flush(); };

/* ---------------------------------------------------------------- content ---
   Nine departments, six years, four kinds of work. Titles are plausible for a
   Philippine college and deliberately varied in subject so the facets have
   something real to separate. */

$AUTHORS = [
    'Marisol D. Enriquez', 'Paolo R. Bautista', 'Kristine J. Salvador', 'Dennis A. Oclarit',
    'Rhea Mae T. Lumapas', 'Jomar C. Delfin', 'Angelica P. Sarmiento', 'Ferdinand L. Cabrera',
    'Nicole B. Tagalog', 'Ramil O. Gundran', 'Charmaine V. Bico', 'Aldrin S. Panerio',
    'Jessa Marie R. Ocampo', 'Bryan K. Montecillo', 'Liezel A. Fuentes', 'Rico M. Alcantara',
    'Divina Grace P. Solano', 'Emmanuel T. Ravelo', 'Katrina L. Bermudez', 'Joseph Ian D. Recto',
];

/* [department code, type, year, title, keywords, abstract] */
$WORKS = [
    ['BSIT','Capstone Project',2026,'An Offline-First Attendance System Using QR Codes for Senior High School Classrooms',
     'attendance system; qr code; offline-first; progressive web app; senior high school',
     'Classroom attendance in schools with unreliable connectivity is still recorded on paper and transcribed later, which delays reporting and introduces errors. This project develops an offline-first attendance system that reads QR-coded student identification cards on a teacher\'s phone, stores records locally, and synchronises when a connection returns. Tested across twelve sections over one grading period, the system reduced transcription time by an average of 41 minutes per section per week.'],

    ['BSIT','Thesis',2025,'Sentiment Classification of Student Feedback in Taglish Using a Fine-Tuned Transformer',
     'sentiment analysis; code-switching; taglish; natural language processing; student feedback',
     'Course evaluation comments written by Filipino students frequently switch between English and Filipino within a single sentence, which degrades the accuracy of sentiment classifiers trained on monolingual corpora. This study assembles a corpus of 8,400 code-switched evaluation comments and fine-tunes a multilingual transformer against it. The resulting classifier achieves an F1 score of 0.87, compared with 0.63 for an English-only baseline applied to the same data.'],

    ['BSIT','Capstone Project',2024,'A Barangay Records Management System with Offline Synchronisation',
     'records management; barangay; offline synchronisation; local government; information system',
     'Barangay offices maintain resident records on paper and in spreadsheets that are rarely reconciled. This project develops a records system that works without a connection and synchronises when one is available, and reports on its deployment across two barangays over a single semester.'],

    ['BSIT','Research Paper',2022,'Usability Evaluation of Government Service Portals Among First-Time Users',
     'usability; e-government; user testing; accessibility; service design',
     'Government service portals assume a level of familiarity that first-time users do not have. This study runs moderated usability tests with 48 participants across four portals and identifies where task failure concentrates.'],

    ['BEEd','Thesis',2026,'Reading Comprehension Gains from Mother-Tongue Scaffolding in Grade 3 Science',
     'mother tongue; multilingual education; reading comprehension; science education; primary education',
     'The mother-tongue-based multilingual education policy assumes that early instruction in a learner\'s first language supports later comprehension in English. This quasi-experimental study follows 214 Grade 3 learners across six schools in Davao City, comparing science reading comprehension between classes using Cebuano scaffolding and classes taught in English alone. Scaffolded classes showed significantly higher comprehension scores, with the gap widest among learners in the lowest baseline quartile.'],

    ['BEEd','Capstone Project',2024,'A Lesson Planning Tool Aligned to the MATATAG Curriculum for Pre-Service Teachers',
     'lesson planning; curriculum alignment; teacher education; instructional design',
     'Pre-service teachers routinely produce lesson plans that satisfy a template without demonstrably aligning to curriculum competencies. This project develops a planning tool that surfaces the relevant competency codes as the plan is written and flags objectives that no competency supports. In trials with 63 student teachers, plans produced with the tool covered 28 per cent more of the intended competencies.'],

    ['BEEd','Research Paper',2022,'Teacher Attrition in Private Secondary Schools in Region XI: A Five-Year Review',
     'teacher attrition; retention; private education; workforce; region xi',
     'Private secondary schools in Region XI report persistent difficulty retaining teachers beyond their third year. This review analyses five years of personnel records from eleven schools alongside exit interviews, and finds that attrition clusters not around salary alone but around the absence of a defined progression path.'],

    ['BSBA','Thesis',2025,'Working Capital Practices of Sari-Sari Store Operators in Davao City',
     'working capital; microenterprise; sari-sari store; informal economy; financial management',
     'Sari-sari stores are the most common form of microenterprise in the Philippines, yet their financial practices are poorly documented. Through structured interviews with 180 operators across three districts, this study documents how inventory, credit extended to customers, and household expenditure interact within a single undifferentiated cash position, and identifies the practices that distinguish stores surviving beyond five years.'],

    ['BSBA','Capstone Project',2023,'A Point-of-Sale and Inventory System for Small Retail Enterprises',
     'point of sale; inventory management; small enterprise; retail systems',
     'Small retailers adopting computerised point-of-sale systems commonly abandon them within months because stock records diverge from physical stock. This project develops a system in which every sale, delivery and adjustment writes to a single immutable movement log, making divergence detectable rather than silent. Deployed in four stores over three months, stock discrepancies were identified within a day rather than at quarterly count.'],

    ['BSBA','Research Paper',2021,'Consumer Adoption of Mobile Wallets Among Public Market Vendors',
     'mobile wallet; financial inclusion; digital payments; public market; adoption',
     'This paper examines why mobile wallet adoption among public market vendors lagged behind adoption among their customers during the period of accelerated digital payment uptake, drawing on interviews with 96 vendors in two Davao City markets.'],

    ['BSN','Thesis',2026,'Hand Hygiene Compliance Among Nursing Students During Clinical Rotation',
     'hand hygiene; infection control; nursing education; clinical compliance; patient safety',
     'Hand hygiene compliance is the single most studied infection control behaviour, yet compliance among students during clinical rotation is less well characterised than among staff. This observational study records 1,340 hand hygiene opportunities across four rotation sites, finding compliance highest before aseptic procedures and lowest after contact with patient surroundings.'],

    ['BSN','Research Paper',2024,'Caregiver Burden Among Family Members of Stroke Survivors in Home Care',
     'caregiver burden; stroke; home care; family caregiving; rehabilitation',
     'Stroke survivors in the Philippines are predominantly cared for at home by family members with no formal training. This study measures caregiver burden among 118 family caregivers and identifies which aspects of care contribute most to it, finding that unpredictability of the survivor\'s needs contributes more than the total hours of care given.'],

    ['BSN','Capstone Project',2022,'A Medication Reminder and Adherence Log for Elderly Outpatients',
     'medication adherence; elderly care; reminder system; outpatient care',
     'Non-adherence to prescribed medication among elderly outpatients is commonly attributed to forgetfulness, but interviews suggest confusion between similar-looking medicines is equally significant. This project develops a reminder system that shows a photograph of the actual medicine alongside each reminder.'],

    ['BSHM','Thesis',2026,'Service Quality and Guest Return Intention in Budget Accommodation in Davao City',
     'service quality; hospitality; guest satisfaction; return intention; budget accommodation',
     'Budget accommodation competes on price, yet operators report that repeat guests matter more to occupancy than rate does. This study surveys 264 guests across nine establishments and finds that staff responsiveness predicts return intention more strongly than either room condition or price.'],

    ['BSHM','Research Paper',2024,'Food Safety Practices Among Street Food Vendors Near Campus Areas',
     'food safety; street food; sanitation; public health; vendor practices',
     'Street food vendors serve a large share of student meals but operate outside routine inspection. This study observes handling practices at 72 stalls and compares them against national sanitation guidance, identifying the practices most often missed.'],

    ['BSHM','Capstone Project',2022,'A Reservation and Table Turnover System for Small Restaurants',
     'reservation system; table management; restaurant operations; turnover',
     'Small restaurants manage reservations in notebooks, which makes table turnover impossible to plan. This project develops a lightweight reservation and seating system and reports on its use across two establishments during a peak season.'],

    ['BSCpE','Thesis',2026,'A Low-Power Sensor Node for Campus Environmental Monitoring',
     'embedded systems; sensor node; low power; environmental monitoring; microcontroller',
     'Battery-powered environmental sensors must transmit reliably without frequent battery replacement. This study designs a sensor node around a low-power microcontroller, measures energy consumption per transmission across four duty-cycling strategies, and identifies the configuration that sustains a full semester on a single charge.'],

    ['BSCpE','Capstone Project',2024,'A Low-Cost Flood Level Monitoring Station for Barangay Early Warning',
     'flood monitoring; early warning; ultrasonic sensor; disaster preparedness; telemetry',
     'Barangay-level flood warning in the Philippines commonly depends on a resident observing a marked pole. This project develops an ultrasonic monitoring station that transmits water level over SMS, requiring no internet connection, and reports on its behaviour across a full rainy season including two flood events.'],

    ['BSCpE','Research Paper',2022,'A Comparative Study of Lightweight Encryption for Low-Power Campus IoT Sensors',
     'internet of things; lightweight cryptography; sensor networks; energy efficiency',
     'Battery-powered environmental sensors deployed around a campus must encrypt their telemetry without exhausting their power budget. This paper benchmarks four lightweight block ciphers on a microcontroller typical of such deployments, measuring energy per encrypted packet alongside throughput and memory footprint, and identifies the conditions under which each becomes the appropriate choice.'],

    ['BSGE','Thesis',2025,'Accuracy Assessment of Smartphone GNSS Against Survey-Grade Receivers for Cadastral Sketching',
     'gnss; cadastral survey; positional accuracy; smartphone positioning; geodetic engineering',
     'Smartphone GNSS is increasingly used for preliminary cadastral sketching despite limited documentation of its accuracy under Philippine conditions. This study compares smartphone positions against survey-grade receivers across 96 control points under varying canopy cover, and reports the conditions under which smartphone positioning falls within tolerance for preliminary work.'],

    ['BSGE','Capstone Project',2023,'A Web-Based Parcel Mapping Tool for Barangay Land Records',
     'gis; parcel mapping; land records; web mapping; barangay',
     'Barangay land records are held as paper sketches with no spatial reference, which makes boundary disputes difficult to resolve. This project develops a web-based mapping tool that ties each parcel sketch to surveyed coordinates and reports on its trial in one barangay.'],

    ['BSGE','Research Paper',2021,'Shoreline Change Detection Along the Davao Gulf Using Multi-Temporal Satellite Imagery',
     'remote sensing; shoreline change; satellite imagery; coastal geodesy; change detection',
     'This paper measures shoreline movement along a stretch of the Davao Gulf across fifteen years of satellite imagery and relates the observed changes to reclamation and storm events.'],

    ['BSHRM','Thesis',2025,'On-the-Job Training Outcomes and Employment Readiness Among Hotel and Restaurant Students',
     'on-the-job training; employability; internship; hospitality education; skills gap',
     'On-the-job training is assumed to prepare students for employment, but employers report gaps on hiring. This study surveys 188 students before and after placement alongside 26 supervising employers, and identifies which competencies improve during placement and which do not.'],

    ['BSHRM','Capstone Project',2023,'A Duty Roster and Shift Scheduling System for Campus Food Service',
     'scheduling; duty roster; shift management; food service; workforce planning',
     'Shift scheduling in campus food service is done by hand and rarely accounts for class timetables, producing conflicts that surface only on the day. This project develops a scheduling system that checks each assignment against the student\'s enrolled schedule before it is confirmed.'],

    ['BSHRM','Research Paper',2021,'Guest Complaint Handling Practices in Small Hospitality Establishments',
     'complaint handling; service recovery; hospitality; guest relations',
     'Service recovery determines whether a dissatisfied guest returns. This study documents complaint handling practices across 54 small establishments and compares them against established service recovery frameworks.'],
];

/* --------------------------------------------------------------- helpers --- */

/** A genuine one-page PDF that states plainly what it is. */
function samplePdf(string $title, string $author, int $year): string {
    $wrap = static function (string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    };
    $lines = [
        [60, 780, 16, $wrap(mb_strimwidth($title, 0, 62, '...'))],
        [60, 752, 11, $wrap($author . '  ·  ' . $year)],
        [60, 726, 11, 'St. John Paul II College of Davao'],
        [60, 680, 10, 'SAMPLE DOCUMENT - GENERATED PLACEHOLDER'],
        [60, 662, 10, 'This file was created by sql/seed-sample-data.php to'],
        [60, 646, 10, 'demonstrate the repository. It is not real research and'],
        [60, 630, 10, 'its stated author is an invented name.'],
    ];
    $content = "BT\n";
    foreach ($lines as [$x, $y, $size, $text]) {
        $content .= "/F1 {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$text}) Tj\n";
    }
    $content .= "ET\n";

    $objs = [
        "<</Type/Catalog/Pages 2 0 R>>",
        "<</Type/Pages/Kids[3 0 R]/Count 1>>",
        "<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>",
        "<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
        "<</Length " . strlen($content) . ">>\nstream\n" . $content . "endstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $body) {
        $offsets[$i] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) { $pdf .= sprintf("%010d 00000 n \n", $o); }
    $pdf .= "trailer\n<</Size " . (count($objs) + 1) . "/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF\n";
    return $pdf;
}

/** Per-year identifier, so a 2023 record does not carry a 2026 number. */
function mintFor(PDO $pdo, int $year): string {
    $s = $pdo->prepare("SELECT dc_identifier FROM records WHERE dc_identifier LIKE ? ORDER BY dc_identifier DESC LIMIT 1");
    $s->execute(["SJP2CD-{$year}-%"]);
    $last = $s->fetchColumn();
    return sprintf('SJP2CD-%d-%04d', $year, $last ? ((int)substr((string)$last, -4)) + 1 : 1);
}

/* ------------------------------------------------------- clean a prior run --- */

$ledger = __DIR__ . '/seeded-ids.json';
if (is_file($ledger)) {
    $prior = json_decode((string)file_get_contents($ledger), true) ?: [];
    if ($prior) {
        $in = implode(',', array_fill(0, count($prior), '?'));
        $files = $pdo->prepare("SELECT storage_path FROM record_files WHERE record_id IN ($in)");
        $files->execute($prior);
        $removed = 0;
        foreach ($files->fetchAll() as $f) {
            $p = (string)$f['storage_path'];
            if ($p !== '' && is_file($p)) { @unlink($p); $removed++; }
        }
        $pdo->prepare("DELETE FROM records WHERE id IN ($in)")->execute($prior);
        $out("Removed " . count($prior) . " records and {$removed} files from a previous run.");
    }
    @unlink($ledger);
}

/* --------------------------------------------------------------- accounts --- */

$depts = [];
foreach ($pdo->query("SELECT id, code FROM departments")->fetchAll() as $d) {
    $depts[$d['code']] = (int)$d['id'];
}
$admin   = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
$faculty = array_map('intval', array_column(
    $pdo->query("SELECT id FROM users WHERE role='faculty' AND is_active=1 ORDER BY id")->fetchAll(), 'id'));
$students = array_map('intval', array_column(
    $pdo->query("SELECT id FROM users WHERE role='student' AND is_active=1 ORDER BY id")->fetchAll(), 'id'));

if (!$admin || !$faculty || !$students) {
    exit("Cannot seed: the repository needs at least one admin, one faculty and one student account.\n");
}

$adminName = (string)$pdo->query("SELECT name FROM users WHERE id={$admin}")->fetchColumn();

/* Statuses: mostly published, with enough in flight that the review queue,
   My deposits and the revision path all have something to show. */
$PLAN = array_merge(
    array_fill(0, 18, 'published'),
    ['submitted', 'submitted', 'under_review', 'revision', 'approved', 'draft', 'published']
);
$ACCESS = ['open','open','open','open','open','open','campus','open','open','restricted'];

if (!is_dir(UPLOAD_PATH)) { @mkdir(UPLOAD_PATH, 0775, true); }

$created = [];
$n = 0;

foreach ($WORKS as $i => [$code, $type, $year, $title, $keywords, $abstract]) {
    $status = $PLAN[$i % count($PLAN)];
    $access = $ACCESS[$i % count($ACCESS)];
    $deptId = $depts[$code] ?? null;
    if (!$deptId) { $out("  skipped (unknown department {$code}): {$title}"); continue; }

    $author  = $AUTHORS[$i % count($AUTHORS)];
    $second  = ($i % 3 === 0) ? '; ' . $AUTHORS[($i + 7) % count($AUTHORS)] : '';
    $creator = $author . $second;

    $isFacultyWork = ($type === 'Faculty Research');
    $depositor = $isFacultyWork ? $faculty[$i % count($faculty)] : $students[$i % count($students)];
    $adviser   = $isFacultyWork ? null : $faculty[$i % count($faculty)];
    $adviserName = $adviser
        ? (string)$pdo->query("SELECT name FROM users WHERE id={$adviser}")->fetchColumn()
        : null;

    try {
        $pdo->beginTransaction();

        $submittedAt = sprintf('%d-%02d-%02d %02d:%02d:00', $year, 2 + ($i % 9), 1 + ($i % 27), 8 + ($i % 9), ($i * 7) % 60);

        $pdo->prepare(
            "INSERT INTO records
               (dc_title, dc_creator, dc_contributor, dc_subject, dc_description,
                dc_publisher, dc_type, dc_language, dc_rights, dc_coverage,
                department_id, access_level, status, submitted_by, adviser_id,
                year_completed, page_count, submitted_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $title, $creator, $adviserName, $keywords, $abstract,
            'St. John Paul II College of Davao', $type, 'English',
            $access === 'open' ? 'Open access — copyright retained by the authors'
                               : 'Copyright retained by the authors',
            'Davao City, Philippines',
            $deptId, $access, $status, $depositor, $adviser,
            $year, 60 + (($i * 13) % 90), $submittedAt, $submittedAt,
        ]);
        $recordId = (int)$pdo->lastInsertId();

        /* ---- the file ---- */
        $pdf        = samplePdf($title, $author, $year);
        $storedName = sprintf('%d_%s.pdf', $recordId, bin2hex(random_bytes(8)));
        $target     = UPLOAD_PATH . '/' . $storedName;
        if (file_put_contents($target, $pdf) === false) {
            throw new RuntimeException('could not write ' . $target);
        }

        $digest = hash_file(DIGEST_ALGO, $target);
        $mime   = 'application/pdf';
        $size   = filesize($target) ?: strlen($pdf);
        $orig   = preg_replace('/[^a-z0-9]+/i', '-', strtolower(mb_strimwidth($title, 0, 48, ''))) . '.pdf';

        $pdo->prepare(
            "INSERT INTO record_files
               (record_id, file_use, original_name, stored_name, storage_path,
                mime_type, size_bytes, checksum_algo, checksum)
             VALUES (?, 'ARCHIVE', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$recordId, $orig, $storedName, $target, $mime, $size, DIGEST_LABEL, $digest]);
        $fileId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE records SET dc_format = ? WHERE id = ?")->execute([$mime, $recordId]);

        /* ---- PREMIS ---- */
        premisObjectForFile($pdo, $recordId, $fileId, [
            'mime_type' => $mime, 'size_bytes' => $size, 'checksum' => $digest,
            'storage_path' => $target, 'original_name' => $orig,
        ]);

        $depositorName = (string)$pdo->query("SELECT name FROM users WHERE id={$depositor}")->fetchColumn();
        $agentDep = premisAgentForUser($pdo, $depositor, $depositorName);
        $service  = premisAgentId($pdo, 'svc:repository', 'SJP2CD Repository Service', 'software');
        $hasher   = premisAgentId($pdo, 'svc:hasher', 'PHP hash_file (SHA-256)', 'software');

        premisEvent($pdo, $recordId, 'ingest', 'success',
            'Deposit received from ' . $depositorName, $agentDep, $fileId);
        premisEvent($pdo, $recordId, 'format identification', 'success',
            'Media type recorded as ' . $mime, $service, $fileId);
        premisEvent($pdo, $recordId, 'message digest calculation', 'success',
            DIGEST_LABEL . ' computed and stored with the object', $hasher, $fileId);

        $pdo->prepare(
            "INSERT INTO premis_rights (record_id, rights_basis, rights_statement, granting_agent, granted_at)
             VALUES (?, 'license', ?, ?, ?)"
        )->execute([$recordId,
            $access === 'open' ? 'Open Access' : 'Restricted — permission required',
            $creator, substr($submittedAt, 0, 10)]);

        buildMetsPackage($pdo, $recordId);

        /* ---- carry the record to its intended state ---- */
        if (in_array($status, ['approved', 'published'], true) && $adviser) {
            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment, created_at)
                 VALUES (?, ?, 'adviser', 'approved', ?, ?)"
            )->execute([$recordId, $adviser,
                'Method and results are sound. Endorsed for publication.', $submittedAt]);
            premisEvent($pdo, $recordId, 'validation', 'success',
                'Adviser approved the submission', premisAgentForUser($pdo, $adviser, (string)$adviserName));
        }

        if ($status === 'revision' && $adviser) {
            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment, created_at)
                 VALUES (?, ?, 'adviser', 'revision', ?, ?)"
            )->execute([$recordId, $adviser,
                'Please expand the discussion of limitations and add the instrument as an appendix.', $submittedAt]);
        }

        if ($status === 'published') {
            $identifier  = mintFor($pdo, $year);
            $publishedAt = sprintf('%d-%02d-%02d 10:%02d:00', $year, 3 + ($i % 8), 2 + ($i % 26), ($i * 11) % 60);
            $pdo->prepare(
                "UPDATE records
                    SET dc_identifier = ?, dc_date_issued = ?, published_at = ?, published_by = ?,
                        views = ?, downloads = ?
                  WHERE id = ?"
            )->execute([$identifier, substr($publishedAt, 0, 10), $publishedAt, $admin,
                        18 + ($i * 37) % 420, 3 + ($i * 13) % 96, $recordId]);

            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment, created_at)
                 VALUES (?, ?, 'library', 'approved', ?, ?)"
            )->execute([$recordId, $admin, 'Metadata checked. Released to the public catalogue.', $publishedAt]);

            premisEvent($pdo, $recordId, 'publication', 'success',
                'Released to the public catalogue as ' . $identifier,
                premisAgentForUser($pdo, $admin, $adminName));

            buildMetsPackage($pdo, $recordId);   /* rebuild so METS carries the identifier */
        }

        if ($status === 'draft') {
            $pdo->prepare("UPDATE records SET submitted_at = NULL WHERE id = ?")->execute([$recordId]);
        }

        $pdo->commit();
        $created[] = $recordId;
        $n++;
        $out(sprintf('  %2d. [%-5s %-16s %d] %-6s  %s', $n, $code, $type, $year, $status,
                     mb_strimwidth($title, 0, 58, '…')));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (isset($target) && is_file($target)) @unlink($target);
        $out('  FAILED: ' . mb_strimwidth($title, 0, 40, '…') . ' — ' . $e->getMessage());
    }
}

file_put_contents($ledger, json_encode($created));

$out('');
$out("Created {$n} sample records. Ids recorded in sql/seeded-ids.json.");
$out('Re-running this script removes exactly these and creates them again.');
$out('');
$out('These are DEMONSTRATION records. The research is invented and the author');
$out('names belong to no one. See the "SEEDED DATA — disclosure" section of SPEC.md.');
