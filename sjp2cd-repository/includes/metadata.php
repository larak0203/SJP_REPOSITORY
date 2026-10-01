<?php
/* ============================================================================
   OBJECTIVE 2 — the metadata engine

   Three frameworks, three jobs:

     Dublin Core   makes a record findable        (records table)
     PREMIS        keeps it trustworthy over time (premis_* tables)
     METS          binds it all into one package  (mets_* tables)

   The depositor fills in a form. Everything below is written for them, so
   Objective 2 and Objective 3 are the same piece of work rather than two
   competing ones.
   ============================================================================ */

declare(strict_types=1);

/* ==========================================================================
   DUBLIN CORE
   ========================================================================== */

/** The 15 elements, mapped to their columns, with what each captures. */
function dublinCoreMap(): array {
    return [
        'dc.title'       => ['dc_title',       'Title of the work'],
        'dc.creator'     => ['dc_creator',     'Author or authors'],
        'dc.contributor' => ['dc_contributor', 'Adviser and other contributors'],
        'dc.subject'     => ['dc_subject',     'Keywords and subject terms'],
        'dc.description' => ['dc_description', 'Abstract'],
        'dc.publisher'   => ['dc_publisher',   'Issuing institution'],
        'dc.date'        => ['dc_date_issued', 'Date issued'],
        'dc.type'        => ['dc_type',        'Nature of the resource'],
        'dc.format'      => ['dc_format',      'Media type of the deposited file'],
        'dc.identifier'  => ['dc_identifier',  'Persistent repository identifier'],
        'dc.source'      => ['dc_source',      'Related source work'],
        'dc.language'    => ['dc_language',    'Language of the full text'],
        'dc.rights'      => ['dc_rights',      'Rights and access condition'],
        'dc.coverage'    => ['dc_coverage',    'Geographic or temporal scope'],
        'dc.relation'    => ['dc_relation',    'Collection or series'],
    ];
}

/** Elements a deposit must carry before it can be submitted for review. */
function dublinCoreRequired(): array {
    return ['dc_title', 'dc_creator', 'dc_subject', 'dc_description', 'dc_type', 'dc_language'];
}

/** Extract the Dublin Core view of a record row. */
function dublinCoreOf(array $record): array {
    $out = [];
    foreach (dublinCoreMap() as $element => [$column, $_]) {
        $value = $record[$column] ?? null;
        if ($value !== null && $value !== '') {
            $out[$element] = (string)$value;
        }
    }
    return $out;
}

/**
 * How complete is this record's description?
 * Drives the live meter on the deposit form (Objective 3).
 */
function metadataCompleteness(array $record): array {
    $required = dublinCoreRequired();
    $optional = ['dc_contributor', 'dc_coverage', 'dc_source', 'dc_relation', 'dc_rights'];

    $have = static fn(string $c): bool => isset($record[$c]) && trim((string)$record[$c]) !== '';

    $reqDone = count(array_filter($required, $have));
    $optDone = count(array_filter($optional, $have));

    $missing = array_values(array_filter($required, static fn($c) => !$have($c)));

    return [
        'required_total' => count($required),
        'required_done'  => $reqDone,
        'optional_total' => count($optional),
        'optional_done'  => $optDone,
        'missing'        => $missing,
        'percent'        => (int)round($reqDone / max(count($required), 1) * 100),
        'complete'       => $reqDone === count($required),
    ];
}

/* ==========================================================================
   PREMIS
   ========================================================================== */

function premisAgentId(PDO $pdo, string $identifier, string $name, string $type, ?int $userId = null): int {
    $s = $pdo->prepare("SELECT id FROM premis_agents WHERE agent_identifier = ?");
    $s->execute([$identifier]);
    $id = $s->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare(
        "INSERT INTO premis_agents (agent_identifier, agent_name, agent_type, user_id)
         VALUES (?, ?, ?, ?)"
    )->execute([$identifier, $name, $type, $userId]);
    return (int)$pdo->lastInsertId();
}

/** The agent representing a person with an account. */
function premisAgentForUser(PDO $pdo, int $userId, string $name): int {
    return premisAgentId($pdo, 'user:' . $userId, $name, 'person', $userId);
}

/** Record one PREMIS event against a record. */
function premisEvent(PDO $pdo, int $recordId, string $type, string $outcome,
                     ?string $detail = null, ?int $agentId = null, ?int $fileId = null): void {
    /* The column is ENUM(success, warning, failure). Callers have written 'fail',
       which MariaDB outside strict mode does not reject -- it stores an empty
       string, so a detected fixity failure was logged with no outcome at all.
       Normalise here, and refuse anything that is not a PREMIS outcome. */
    $outcome = match (strtolower($outcome)) {
        'success', 'pass', 'passed', 'ok'  => 'success',
        'warning', 'warn'                   => 'warning',
        'failure', 'fail', 'failed'        => 'failure',
        default => throw new InvalidArgumentException("Not a PREMIS event outcome: $outcome"),
    };
    $pdo->prepare(
        "INSERT INTO premis_events
            (record_id, file_id, event_type, event_datetime, outcome, outcome_detail, agent_id)
         VALUES (?, ?, ?, NOW(), ?, ?, ?)"
    )->execute([$recordId, $fileId, $type, $outcome, $detail, $agentId]);
}

/** Create the PREMIS object describing a stored file. */
function premisObjectForFile(PDO $pdo, int $recordId, int $fileId, array $file): void {
    $identifier = sprintf('sjp2cd:obj:%d:%03d', $recordId, $fileId);

    $pdo->prepare(
        "INSERT INTO premis_objects
            (record_id, file_id, object_identifier, object_category, format_name,
             format_registry, size_bytes, digest_algorithm, message_digest,
             preservation_level, storage_location, original_name)
         VALUES (?, ?, ?, 'file', ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $recordId, $fileId, $identifier,
        $file['mime_type'] ?? null,
        premisFormatRegistry((string)($file['mime_type'] ?? '')),
        $file['size_bytes'] ?? 0,
        DIGEST_LABEL,
        $file['checksum'] ?? null,
        'Full preservation (bit-level integrity)',
        $file['storage_path'] ?? null,
        $file['original_name'] ?? null,
    ]);
}

/** PRONOM identifiers for the formats this repository accepts. */
function premisFormatRegistry(string $mime): string {
    return match (true) {
        str_contains($mime, 'pdf')  => 'PRONOM fmt/276',
        str_contains($mime, 'word'),
        str_contains($mime, 'officedocument.wordprocessingml') => 'PRONOM fmt/412',
        default => 'unclassified',
    };
}

/** How likely is this format to remain readable? Drives the format register. */
function formatRisk(string $mimeOrName): array {
    $f = strtolower($mimeOrName);
    return match (true) {
        str_contains($f, 'pdf/a')  => ['low',    'Archival format'],
        str_contains($f, 'pdf')    => ['low',    'Widely supported'],
        str_contains($f, 'wordprocessingml'), str_contains($f, 'docx')
                                   => ['medium', 'Office XML — migrate to PDF/A'],
        str_contains($f, 'msword'), str_contains($f, '/doc')
                                   => ['high',   'Legacy binary — migrate urgently'],
        str_contains($f, 'text'), str_contains($f, 'csv')
                                   => ['low',    'Plain text'],
        default                    => ['medium', 'Unclassified — review'],
    };
}

/** Every PREMIS event recorded for a record, newest last. */
function premisEventsFor(PDO $pdo, int $recordId): array {
    $s = $pdo->prepare(
        "SELECT e.*, a.agent_name, a.agent_type
         FROM premis_events e
         LEFT JOIN premis_agents a ON a.id = e.agent_id
         WHERE e.record_id = ? ORDER BY e.event_datetime ASC, e.id ASC"
    );
    $s->execute([$recordId]);
    return $s->fetchAll();
}

function premisObjectsFor(PDO $pdo, int $recordId): array {
    $s = $pdo->prepare("SELECT * FROM premis_objects WHERE record_id = ?");
    $s->execute([$recordId]);
    return $s->fetchAll();
}

/**
 * Re-hash a stored file and compare with the digest taken at ingest.
 * This is the actual proof behind "long-term preservation".
 */
function verifyFixity(array $file): array {
    $path = $file['storage_path'] ?? '';
    if (!$path || !is_file($path)) {
        return ['result' => 'missing_file', 'expected' => $file['checksum'] ?? null, 'actual' => null];
    }
    if (empty($file['checksum'])) {
        return ['result' => 'no_digest', 'expected' => null, 'actual' => null];
    }
    $actual = hash_file(DIGEST_ALGO, $path);
    $ok = $actual !== false && hash_equals(strtolower((string)$file['checksum']), strtolower((string)$actual));
    return [
        'result'   => $ok ? 'passed' : 'failed',
        'expected' => $file['checksum'],
        'actual'   => $actual ?: null,
    ];
}

/* ==========================================================================
   METS
   ========================================================================== */

/** Store an uploaded manuscript against a record.

    Moves the file in under a system-chosen name, fingerprints it, registers it
    as a PREMIS object and logs the ingest, format and digest events. A new
    deposit and a revised manuscript go through exactly this, so a revision is
    preserved as carefully as the original was.

    If anything after the move fails, the file is removed again before the
    exception is passed on, so a failed save never leaves an orphan on disk. */
function storeManuscript(PDO $pdo, int $recordId, array $file, int $userId, string $userName,
                         string $ingestNote): array {
    if (!is_dir(UPLOAD_PATH)) { mkdir(UPLOAD_PATH, 0775, true); }
    $ext        = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $storedName = sprintf('%d_%s.%s', $recordId, bin2hex(random_bytes(8)), $ext);
    $target     = UPLOAD_PATH . '/' . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the file into uploads/records.');
    }

    try {
        $digest = hash_file(DIGEST_ALGO, $target);
        $mime   = mime_content_type($target) ?: null;
        $size   = filesize($target) ?: 0;

        $pdo->prepare(
            "INSERT INTO record_files
               (record_id, file_use, original_name, stored_name, storage_path,
                mime_type, size_bytes, checksum_algo, checksum)
             VALUES (?, 'ARCHIVE', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$recordId, $file['name'], $storedName, $target, $mime, $size, DIGEST_LABEL, $digest]);
        $fileId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE records SET dc_format = ? WHERE id = ?")->execute([$mime, $recordId]);

        premisObjectForFile($pdo, $recordId, $fileId, [
            'mime_type'     => $mime,
            'size_bytes'    => $size,
            'checksum'      => $digest,
            'storage_path'  => $target,
            'original_name' => $file['name'],
        ]);

        $person  = premisAgentForUser($pdo, $userId, $userName);
        $service = premisAgentId($pdo, 'svc:repository', 'SJP2CD Repository Service', 'software');
        $hasher  = premisAgentId($pdo, 'svc:hasher', 'PHP hash_file (SHA-256)', 'software');

        premisEvent($pdo, $recordId, 'ingest', 'success', $ingestNote, $person, $fileId);
        premisEvent($pdo, $recordId, 'format identification', 'success',
            'Media type recorded as ' . ($mime ?: 'unknown'), $service, $fileId);
        premisEvent($pdo, $recordId, 'message digest calculation', 'success',
            DIGEST_LABEL . ' computed and stored with the object', $hasher, $fileId);
    } catch (Throwable $e) {
        if (is_file($target)) @unlink($target);
        throw $e;
    }

    return ['file_id' => $fileId, 'path' => $target, 'mime' => $mime];
}

/** Everyone who should hear about something waiting on the library. */
function notifyLibrary(PDO $pdo, string $title, string $message, string $type, ?int $recordId = null): void {
    foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll() as $a) {
        notify($pdo, (int)$a['id'], $title, $message, $type, $recordId);
    }
}

/** The dc.rights statement for an access level.

    This used to be a free-text box pre-filled with "Open Access", which went
    on saying so even when the depositor chose Campus only or Metadata only --
    two fields describing the same thing, able to contradict each other. The
    statement is now derived from the access level, so it cannot drift. */
function rightsStatementFor(string $access): string {
    $tail = ' Copyright remains with the author(s).';
    return match ($access) {
        'campus'     => 'Campus access. The full text is available to St. John Paul II College of Davao accounts.' . $tail,
        'restricted' => 'Restricted. The description is public; the full text is available on request to the College Library.' . $tail,
        default      => 'Open access. Free to read and download for study and research.' . $tail,
    };
}

/** Default logical structure for the METS structural map. */
function metsDefaultDivisions(): array {
    return [
        'Preliminaries',
        'Chapter 1 — The Problem and Its Setting',
        'Chapter 2 — Review of Related Literature',
        'Chapter 3 — Methodology',
        'Chapter 4 — Results and Discussion',
        'Chapter 5 — Conclusions and Recommendations',
        'References',
        'Appendices',
    ];
}

/** Build (or rebuild) the METS package rows for a record. Idempotent. */
function buildMetsPackage(PDO $pdo, int $recordId, ?array $divisionLabels = null): void {
    $rs = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $rs->execute([$recordId]);
    $record = $rs->fetch();
    if (!$record) return;

    $objid = 'SJP2CD-METS-' . str_pad((string)$recordId, 6, '0', STR_PAD_LEFT);

    $pdo->prepare(
        "INSERT INTO mets_packages
            (record_id, objid, profile, label, mets_type, create_date, last_mod_date)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            label = VALUES(label), mets_type = VALUES(mets_type), last_mod_date = NOW()"
    )->execute([
        $recordId, $objid, METS_PROFILE,
        mb_substr((string)$record['dc_title'], 0, 500),
        (string)$record['dc_type'],
    ]);

    /* ---- fileSec ---- */
    $pdo->prepare("DELETE FROM mets_files WHERE record_id = ?")->execute([$recordId]);
    $files = $pdo->prepare("SELECT * FROM record_files WHERE record_id = ? AND superseded_at IS NULL ORDER BY id");
    $files->execute([$recordId]);

    $n = 0;
    $insFile = $pdo->prepare(
        "INSERT INTO mets_files
            (record_id, file_id, mets_fileid, file_grp_use, mimetype, size_bytes, checksum, checksum_type, href)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($files->fetchAll() as $f) {
        $n++;
        $fileId = sprintf('file-%03d', $n);
        $insFile->execute([
            $recordId, $f['id'], $fileId, $f['file_use'], $f['mime_type'],
            $f['size_bytes'], $f['checksum'], $f['checksum_algo'],
            /* FLocat declares LOCTYPE="URL", so it has to carry one. It used to
               carry the server's own path instead, which is not a URL, is no use
               to a harvester, and told every reader of a public package where the
               files sit on disk and what they are really named. The address given
               is the gated download -- the only way into a file anyway. */
            rtrim(BASE_URL, '/') . '/download.php?id=' . $recordId,
        ]);
    }
    if ($n === 0) { $fileId = null; } else { $fileId = 'file-001'; }

    /* ---- structMap ---- */
    $pdo->prepare("DELETE FROM mets_divisions WHERE record_id = ?")->execute([$recordId]);
    $labels = $divisionLabels ?: metsDefaultDivisions();
    $insDiv = $pdo->prepare(
        "INSERT INTO mets_divisions (record_id, div_order, div_level, div_type, div_label, file_pointer)
         VALUES (?, ?, 1, 'div', ?, ?)"
    );
    foreach (array_values($labels) as $i => $label) {
        $label = trim((string)$label);
        if ($label === '') continue;
        $insDiv->execute([$recordId, $i, $label, $fileId]);
    }
}

function metsPackageOf(PDO $pdo, int $recordId): ?array {
    $p = $pdo->prepare("SELECT * FROM mets_packages WHERE record_id = ?");
    $p->execute([$recordId]);
    $pkg = $p->fetch();
    if (!$pkg) return null;

    $f = $pdo->prepare("SELECT * FROM mets_files WHERE record_id = ? ORDER BY file_grp_use, mets_fileid");
    $f->execute([$recordId]);
    $d = $pdo->prepare("SELECT * FROM mets_divisions WHERE record_id = ? ORDER BY div_order");
    $d->execute([$recordId]);

    return ['package' => $pkg, 'files' => $f->fetchAll(), 'divisions' => $d->fetchAll()];
}

/**
 * Serialise the complete METS XML: Dublin Core in dmdSec, PREMIS in amdSec,
 * the files in fileSec and the structure in structMap.
 */
function buildMetsXml(PDO $pdo, int $recordId): ?string {
    $rs = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $rs->execute([$recordId]);
    $record = $rs->fetch();
    if (!$record) return null;

    $mets = metsPackageOf($pdo, $recordId);
    if (!$mets) { buildMetsPackage($pdo, $recordId); $mets = metsPackageOf($pdo, $recordId); }
    if (!$mets) return null;

    $pkg     = $mets['package'];
    $objects = premisObjectsFor($pdo, $recordId);
    $events  = premisEventsFor($pdo, $recordId);

    $x   = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $iso = static fn($v): string => $v ? date('c', strtotime((string)$v)) : date('c');

    /* dmdSec — Dublin Core */
    $dcXml = '';
    foreach (dublinCoreOf($record) as $element => $value) {
        $tag = str_replace('dc.', 'dc:', $element);
        $dcXml .= "          <{$tag}>" . $x($value) . "</{$tag}>\n";
    }

    /* amdSec — PREMIS objects */
    $objXml = '';
    foreach ($objects as $o) {
        $objXml .=
"          <premis:object xsi:type=\"premis:file\">
            <premis:objectIdentifier>
              <premis:objectIdentifierType>local</premis:objectIdentifierType>
              <premis:objectIdentifierValue>" . $x($o['object_identifier']) . "</premis:objectIdentifierValue>
            </premis:objectIdentifier>
            <premis:objectCharacteristics>
              <premis:compositionLevel>0</premis:compositionLevel>
              <premis:fixity>
                <premis:messageDigestAlgorithm>" . $x($o['digest_algorithm']) . "</premis:messageDigestAlgorithm>
                <premis:messageDigest>" . $x($o['message_digest']) . "</premis:messageDigest>
              </premis:fixity>
              <premis:size>" . $x($o['size_bytes']) . "</premis:size>
              <premis:format>
                <premis:formatDesignation>
                  <premis:formatName>" . $x($o['format_name']) . "</premis:formatName>
                </premis:formatDesignation>
                <premis:formatRegistry>
                  <premis:formatRegistryName>PRONOM</premis:formatRegistryName>
                  <premis:formatRegistryKey>" . $x($o['format_registry']) . "</premis:formatRegistryKey>
                </premis:formatRegistry>
              </premis:format>
            </premis:objectCharacteristics>
            <premis:preservationLevel>
              <premis:preservationLevelValue>" . $x($o['preservation_level']) . "</premis:preservationLevelValue>
            </premis:preservationLevel>
            <premis:storage>
              <premis:contentLocation>
                <premis:contentLocationType>filesystem</premis:contentLocationType>
                <premis:contentLocationValue>" . $x($o['storage_location']) . "</premis:contentLocationValue>
              </premis:contentLocation>
            </premis:storage>
          </premis:object>\n";
    }

    /* amdSec — PREMIS events */
    $evXml = '';
    foreach ($events as $i => $ev) {
        $evXml .=
"          <premis:event>
            <premis:eventIdentifier>
              <premis:eventIdentifierType>local</premis:eventIdentifierType>
              <premis:eventIdentifierValue>evt:" . $x($recordId) . ':' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . "</premis:eventIdentifierValue>
            </premis:eventIdentifier>
            <premis:eventType>" . $x($ev['event_type']) . "</premis:eventType>
            <premis:eventDateTime>" . $x($iso($ev['event_datetime'])) . "</premis:eventDateTime>
            <premis:eventOutcomeInformation>
              <premis:eventOutcome>" . $x($ev['outcome']) . "</premis:eventOutcome>
              <premis:eventOutcomeDetail>
                <premis:eventOutcomeDetailNote>" . $x($ev['outcome_detail']) . "</premis:eventOutcomeDetailNote>
              </premis:eventOutcomeDetail>
            </premis:eventOutcomeInformation>
            <premis:linkingAgentIdentifier>
              <premis:linkingAgentIdentifierValue>" . $x($ev['agent_name'] ?: 'repository service') . "</premis:linkingAgentIdentifierValue>
            </premis:linkingAgentIdentifier>
          </premis:event>\n";
    }

    /* fileSec */
    $groups = [];
    foreach ($mets['files'] as $f) { $groups[$f['file_grp_use']][] = $f; }
    $fileSec = '';
    foreach ($groups as $use => $items) {
        $fileSec .= "    <mets:fileGrp USE=\"" . $x($use) . "\">\n";
        foreach ($items as $f) {
            $fileSec .=
"      <mets:file ID=\"" . $x($f['mets_fileid']) . "\" MIMETYPE=\"" . $x($f['mimetype']) . "\""
              . " SIZE=\"" . $x($f['size_bytes']) . "\" CHECKSUM=\"" . $x($f['checksum']) . "\""
              . " CHECKSUMTYPE=\"" . $x($f['checksum_type']) . "\">\n"
. "        <mets:FLocat LOCTYPE=\"URL\" xlink:href=\"" . $x($f['href']) . "\"/>\n"
. "      </mets:file>\n";
        }
        $fileSec .= "    </mets:fileGrp>\n";
    }

    /* structMap */
    $divs = '';
    foreach ($mets['divisions'] as $d) {
        $divs .=
"        <mets:div TYPE=\"" . $x($d['div_type']) . "\" LABEL=\"" . $x($d['div_label']) . "\" ORDER=\"" . $x($d['div_order'] + 1) . "\">\n"
. ($d['file_pointer'] ? "          <mets:fptr FILEID=\"" . $x($d['file_pointer']) . "\"/>\n" : '')
. "        </mets:div>\n";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<mets:mets xmlns:mets="http://www.loc.gov/METS/"
           xmlns:dc="http://purl.org/dc/elements/1.1/"
           xmlns:premis="http://www.loc.gov/premis/v3"
           xmlns:xlink="http://www.w3.org/1999/xlink"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           OBJID="' . $x($pkg['objid']) . '"
           LABEL="' . $x($pkg['label']) . '"
           TYPE="' . $x($pkg['mets_type']) . '"
           PROFILE="' . $x($pkg['profile']) . '">

  <!-- ===== METS HEADER ===== -->
  <mets:metsHdr CREATEDATE="' . $x($iso($pkg['create_date'])) . '"
                LASTMODDATE="' . $x($iso($pkg['last_mod_date'])) . '"
                RECORDSTATUS="' . $x($pkg['record_status']) . '">
    <mets:agent ROLE="' . $x($pkg['agent_role']) . '" TYPE="' . $x($pkg['agent_type']) . '">
      <mets:name>' . $x($pkg['agent_name']) . '</mets:name>
    </mets:agent>
  </mets:metsHdr>

  <!-- ===== DESCRIPTIVE METADATA — Dublin Core ===== -->
  <mets:dmdSec ID="dmd-001">
    <mets:mdWrap MDTYPE="DC" LABEL="Dublin Core Metadata">
      <mets:xmlData>
        <dc:record>
' . $dcXml . '        </dc:record>
      </mets:xmlData>
    </mets:mdWrap>
  </mets:dmdSec>

  <!-- ===== ADMINISTRATIVE METADATA — PREMIS ===== -->
  <mets:amdSec>
    <mets:techMD ID="tech-001">
      <mets:mdWrap MDTYPE="PREMIS:OBJECT" LABEL="PREMIS Object">
        <mets:xmlData>
' . $objXml . '        </mets:xmlData>
      </mets:mdWrap>
    </mets:techMD>

    <mets:digiprovMD ID="prov-001">
      <mets:mdWrap MDTYPE="PREMIS:EVENT" LABEL="PREMIS Provenance">
        <mets:xmlData>
' . $evXml . '        </mets:xmlData>
      </mets:mdWrap>
    </mets:digiprovMD>

    <mets:rightsMD ID="rights-001">
      <mets:mdWrap MDTYPE="OTHER" OTHERMDTYPE="DC-RIGHTS" LABEL="Rights">
        <mets:xmlData>
          <dc:rights>' . $x($record['dc_rights']) . '</dc:rights>
        </mets:xmlData>
      </mets:mdWrap>
    </mets:rightsMD>
  </mets:amdSec>

  <!-- ===== FILE SECTION ===== -->
  <mets:fileSec>
' . $fileSec . '  </mets:fileSec>

  <!-- ===== STRUCTURAL MAP ===== -->
  <mets:structMap TYPE="LOGICAL" LABEL="Logical structure">
    <mets:div TYPE="' . $x($pkg['mets_type']) . '" LABEL="' . $x($pkg['label']) . '"
              DMDID="dmd-001" ADMID="tech-001 prov-001">
' . $divs . '    </mets:div>
  </mets:structMap>

</mets:mets>
';
}

/** Human element name ("dc.title") for a records-table column. */
function dcElementName(string $column): string {
    static $flip = null;
    if ($flip === null) {
        $flip = [];
        foreach (dublinCoreMap() as $element => [$col, $_]) { $flip[$col] = $element; }
    }
    return $flip[$column] ?? $column;
}

/** How a fixity result should read on screen. */
function fixityVerdict(array $check): array {
    return match ($check['result']) {
        'passed'       => ['ok' => true,  'badge' => 'badge-ok',     'icon' => 'shieldcheck',
                           'text' => 'Fixity holds'],
        'failed'       => ['ok' => false, 'badge' => 'badge-danger', 'icon' => 'alert',
                           'text' => 'Checksum mismatch'],
        'missing_file' => ['ok' => false, 'badge' => 'badge-danger', 'icon' => 'alert',
                           'text' => 'File missing from storage'],
        default        => ['ok' => false, 'badge' => 'badge-warn',   'icon' => 'help',
                           'text' => 'No digest recorded'],
    };
}

/* ==========================================================================
   Automatic archiving
   --------------------------------------------------------------------------
   The paper's Scope and Limitation states that research documents older than
   five years are automatically moved to an archive section.

   Archiving is not deletion. The record keeps its identifier, its metadata, its
   files and its whole preservation history; it simply leaves the main catalogue
   listing and moves to the archive view, where it can still be searched, opened
   and downloaded. A deaccession event is written so the move is auditable, and
   library staff can restore any record.

   XAMPP has no scheduler, so this runs opportunistically on library page loads
   and is throttled to once a day. It is idempotent — running it twice in a row
   changes nothing the second time.
   ========================================================================== */

/** Records eligible for archiving: published, and older than the cut-off. */
function recordsDueForArchive(PDO $pdo): array {
    $cutoff = (int)date('Y') - ARCHIVE_AFTER_YEARS;
    $s = $pdo->prepare(
        "SELECT id, dc_title, dc_identifier, year_completed, submitted_by
         FROM records
         WHERE status = 'published'
           AND year_completed IS NOT NULL
           AND year_completed <= ?
         ORDER BY year_completed, id"
    );
    $s->execute([$cutoff]);
    return $s->fetchAll();
}

/**
 * Move everything past the cut-off into the archive.
 * Returns the number archived. Safe to call on every request.
 */
function archiveExpiredRecords(PDO $pdo, ?int $actorId = null): int {
    $due = recordsDueForArchive($pdo);
    if (!$due) return 0;

    $agent = $actorId
        ? premisAgentForUser($pdo, $actorId, (string)($pdo->query(
              "SELECT name FROM users WHERE id = " . (int)$actorId)->fetchColumn() ?: 'Repository'))
        : premisAgentId($pdo, 'svc:repository', 'SJP2CD Repository Service', 'software');

    $mark = $pdo->prepare("UPDATE records SET status = 'archived', archived_at = NOW() WHERE id = ?");
    $n = 0;

    foreach ($due as $r) {
        $mark->execute([(int)$r['id']]);
        premisEvent(
            $pdo, (int)$r['id'], 'deaccession', 'success',
            'Moved to the archive automatically: more than ' . ARCHIVE_AFTER_YEARS
            . ' years old (completed ' . (int)$r['year_completed'] . ')',
            $agent
        );
        notify($pdo, (int)$r['submitted_by'], 'Your record moved to the archive',
            $r['dc_title'] . ' is more than ' . ARCHIVE_AFTER_YEARS
            . ' years old and now sits in the archive. It keeps its identifier and remains readable.',
            'archived', (int)$r['id']);
        $n++;
    }

    logActivity($pdo, $actorId, 'archived ' . $n . ' record(s) automatically',
        'Older than ' . ARCHIVE_AFTER_YEARS . ' years');
    return $n;
}

/**
 * Run the rule at most once a day, so it costs one cheap query per session
 * rather than a write on every page load.
 */
function runArchiveSweep(PDO $pdo, ?int $actorId = null): int {
    $today = date('Y-m-d');
    if (($_SESSION['archive_swept'] ?? '') === $today) return 0;
    $_SESSION['archive_swept'] = $today;
    return archiveExpiredRecords($pdo, $actorId);
}
