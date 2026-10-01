-- ============================================================================
--  SJP2CD INSTITUTIONAL REPOSITORY — DATABASE SCHEMA
--  St. John Paul II College of Davao
--
--  Designed around the three research objectives:
--
--   OBJECTIVE 1  Upload, storage and management of theses, capstone projects
--                and research papers.
--                -> records, record_files, submission_reviews, departments
--
--   OBJECTIVE 2  Metadata frameworks based on Dublin Core, PREMIS and METS
--                for discoverability and long-term preservation.
--                -> records (Dublin Core columns)
--                   premis_objects / premis_events / premis_agents
--                   mets_packages / mets_files / mets_divisions
--                   fixity_audits / fixity_results
--
--   OBJECTIVE 3  An interface that simplifies submission and retrieval,
--                encouraging self-archiving.
--                -> submission drafts, adviser routing, notifications
--
--  Run once:
--     mysql -u root < sql/schema.sql
-- ============================================================================

DROP DATABASE IF EXISTS sjp2cd_repository;
CREATE DATABASE sjp2cd_repository
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE sjp2cd_repository;


-- ============================================================================
--  REFERENCE DATA
-- ============================================================================

CREATE TABLE departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================================
--  OBJECTIVE 1 — PEOPLE
--  Three roles with distinct jobs:
--    student  submits own work, routed to an adviser
--    faculty  reviews advisees' submissions, deposits own research
--    admin    publishes, manages users, runs preservation
-- ============================================================================

CREATE TABLE users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    email          VARCHAR(190) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('student','faculty','admin') NOT NULL DEFAULT 'student',
    department_id  INT NULL,
    student_number VARCHAR(40)  NULL,
    adviser_id     INT NULL COMMENT 'students: the faculty member who reviews their work',
    avatar         VARCHAR(255) NULL,
    is_active      TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at  DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_users_role (role),
    KEY idx_users_adviser (adviser_id),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_adviser    FOREIGN KEY (adviser_id)    REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================================
--  OBJECTIVE 1 + 2 — THE RECORD
--  Dublin Core lives here as first-class columns, so description is captured
--  at deposit rather than catalogued afterwards.
--
--  Workflow status reflects the real path:
--    draft -> submitted -> under_review -> (revision) -> approved -> published
--                                       -> rejected
--    published -> archived
-- ============================================================================

CREATE TABLE records (
    id               INT AUTO_INCREMENT PRIMARY KEY,

    -- ---------- Dublin Core (ISO 15836) ----------
    dc_title         VARCHAR(500) NOT NULL,
    dc_creator       VARCHAR(500) NOT NULL COMMENT 'author(s), semicolon separated',
    dc_contributor   VARCHAR(500) NULL     COMMENT 'everyone credited; built from adviser + panel',
    adviser          VARCHAR(255) NULL,
    panel            VARCHAR(500) NULL,   -- semicolon-separated
    dc_subject       VARCHAR(500) NULL     COMMENT 'keywords, semicolon separated',
    dc_description   TEXT         NULL     COMMENT 'abstract',
    dc_publisher     VARCHAR(255) NOT NULL DEFAULT 'St. John Paul II College of Davao',
    dc_date_issued   DATE         NULL     COMMENT 'set when published',
    dc_type          ENUM('Thesis','Capstone Project','Research Paper','Faculty Research') NOT NULL,
    dc_format        VARCHAR(100) NULL     COMMENT 'MIME type of the deposited file',
    dc_identifier    VARCHAR(60)  NULL UNIQUE COMMENT 'SJP2CD-YYYY-NNNN, minted at publication',
    dc_source        VARCHAR(255) NULL,
    dc_language      VARCHAR(40)  NOT NULL DEFAULT 'English',
    dc_rights        VARCHAR(255) NULL,
    dc_coverage      VARCHAR(255) NULL     COMMENT 'geographic or temporal scope',
    dc_relation      VARCHAR(255) NULL     COMMENT 'collection or series',

    -- ---------- management ----------
    department_id    INT          NULL,
    access_level     ENUM('open','campus','restricted') NOT NULL DEFAULT 'open'
                     COMMENT 'open=public, campus=signed-in only, restricted=metadata only',
    embargo_until    DATE         NULL COMMENT 'opens automatically on this date',
    status           ENUM('draft','submitted','under_review','revision','approved','published','rejected','archived')
                     NOT NULL DEFAULT 'draft',

    submitted_by     INT          NOT NULL COMMENT 'who deposited it',
    adviser_id       INT          NULL     COMMENT 'faculty reviewer for student submissions',
    published_by     INT          NULL     COMMENT 'admin who released it',

    year_completed   SMALLINT     NULL,
    page_count       INT          NULL,
    downloads        INT          NOT NULL DEFAULT 0,
    views            INT          NOT NULL DEFAULT 0,

    submitted_at     DATETIME     NULL,
    published_at     DATETIME     NULL,
    archived_at      DATETIME     NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FULLTEXT KEY ft_records (dc_title, dc_creator, dc_subject, dc_description),
    KEY idx_records_status (status),
    KEY idx_records_type (dc_type),
    KEY idx_records_dept (department_id),
    KEY idx_records_adviser (adviser_id),
    KEY idx_records_submitter (submitted_by),

    CONSTRAINT fk_records_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_records_submitter  FOREIGN KEY (submitted_by)  REFERENCES users(id)       ON DELETE CASCADE,
    CONSTRAINT fk_records_adviser    FOREIGN KEY (adviser_id)    REFERENCES users(id)       ON DELETE SET NULL,
    CONSTRAINT fk_records_publisher  FOREIGN KEY (published_by)  REFERENCES users(id)       ON DELETE SET NULL
) ENGINE=InnoDB;


-- ----------------------------------------------------------------------------
--  Files. SHA-256 from the outset — MD5 cannot prove a file is unaltered.
--  Two uses per deposit: an ARCHIVE master and an ACCESS copy.
-- ----------------------------------------------------------------------------
CREATE TABLE record_files (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    record_id      INT NOT NULL,
    file_use       ENUM('ARCHIVE','ACCESS','SUPPLEMENT') NOT NULL DEFAULT 'ARCHIVE',
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(255) NOT NULL,
    storage_path   VARCHAR(500) NOT NULL,
    mime_type      VARCHAR(120) NULL,
    size_bytes     BIGINT       NOT NULL DEFAULT 0,
    checksum_algo  VARCHAR(20)  NOT NULL DEFAULT 'SHA-256',
    checksum       VARCHAR(128) NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at  TIMESTAMP    NULL DEFAULT NULL,   -- set when a revision replaces it; the file is kept

    KEY idx_files_record (record_id),
    CONSTRAINT fk_files_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ----------------------------------------------------------------------------
--  Every review decision, kept as an auditable trail (Objective 1 + 3)
-- ----------------------------------------------------------------------------
CREATE TABLE submission_reviews (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    record_id    INT NOT NULL,
    reviewer_id  INT NULL,
    stage        ENUM('adviser','library') NOT NULL,
    decision     ENUM('approved','revision','rejected') NOT NULL,
    comment      TEXT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_reviews_record (record_id),
    CONSTRAINT fk_reviews_record   FOREIGN KEY (record_id)   REFERENCES records(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================================
--  OBJECTIVE 2 — PREMIS
--  Four entities: Object, Event, Agent, Rights.
-- ============================================================================

CREATE TABLE premis_agents (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    agent_identifier VARCHAR(150) NOT NULL UNIQUE,
    agent_name       VARCHAR(255) NOT NULL,
    agent_type       ENUM('person','software','organization') NOT NULL,
    user_id          INT NULL COMMENT 'set when the agent is a person with an account',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_agents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE premis_objects (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    record_id          INT NOT NULL,
    file_id            INT NULL,
    object_identifier  VARCHAR(190) NOT NULL UNIQUE,
    object_category    ENUM('file','representation','bitstream') NOT NULL DEFAULT 'file',
    format_name        VARCHAR(120) NULL,
    format_version     VARCHAR(60)  NULL,
    format_registry    VARCHAR(120) NULL COMMENT 'e.g. PRONOM fmt/276',
    size_bytes         BIGINT       NULL,
    digest_algorithm   VARCHAR(20)  NOT NULL DEFAULT 'SHA-256',
    message_digest     VARCHAR(128) NULL,
    preservation_level VARCHAR(80)  NOT NULL DEFAULT 'Full preservation',
    storage_location   VARCHAR(500) NULL,
    original_name      VARCHAR(255) NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_premis_obj_record (record_id),
    CONSTRAINT fk_premis_obj_record FOREIGN KEY (record_id) REFERENCES records(id)      ON DELETE CASCADE,
    CONSTRAINT fk_premis_obj_file   FOREIGN KEY (file_id)   REFERENCES record_files(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE premis_events (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    record_id      INT NOT NULL,
    file_id        INT NULL,
    event_type     VARCHAR(60) NOT NULL
                   COMMENT 'ingest | virus check | format validation | message digest calculation | review | publication | fixity check | migration | deaccession',
    event_datetime DATETIME    NOT NULL,
    outcome        ENUM('success','warning','failure') NOT NULL DEFAULT 'success',
    outcome_detail TEXT NULL,
    agent_id       INT NULL,
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_premis_ev_record (record_id),
    KEY idx_premis_ev_type (event_type),
    CONSTRAINT fk_premis_ev_record FOREIGN KEY (record_id) REFERENCES records(id)       ON DELETE CASCADE,
    CONSTRAINT fk_premis_ev_file   FOREIGN KEY (file_id)   REFERENCES record_files(id)  ON DELETE SET NULL,
    CONSTRAINT fk_premis_ev_agent  FOREIGN KEY (agent_id)  REFERENCES premis_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE premis_rights (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    record_id      INT NOT NULL,
    rights_basis   ENUM('license','copyright','institutional policy') NOT NULL DEFAULT 'license',
    rights_statement TEXT NULL,
    granting_agent VARCHAR(255) NULL,
    granted_at     DATE NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_premis_rights_record (record_id),
    CONSTRAINT fk_premis_rights_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ----------------------------------------------------------------------------
--  Fixity auditing — the evidence behind "long-term preservation"
-- ----------------------------------------------------------------------------
CREATE TABLE fixity_audits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    run_at          DATETIME NOT NULL,
    run_by          INT NULL,
    objects_checked INT NOT NULL DEFAULT 0,
    passed          INT NOT NULL DEFAULT 0,
    failed          INT NOT NULL DEFAULT 0,
    unverifiable    INT NOT NULL DEFAULT 0,
    duration_ms     INT NULL,

    CONSTRAINT fk_audit_user FOREIGN KEY (run_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE fixity_results (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    audit_id        INT NOT NULL,
    file_id         INT NULL,
    record_id       INT NULL,
    expected_digest VARCHAR(128) NULL,
    actual_digest   VARCHAR(128) NULL,
    result          ENUM('passed','failed','missing_file','no_digest') NOT NULL,

    KEY idx_fixres_audit (audit_id),
    CONSTRAINT fk_fixres_audit  FOREIGN KEY (audit_id)  REFERENCES fixity_audits(id) ON DELETE CASCADE,
    CONSTRAINT fk_fixres_file   FOREIGN KEY (file_id)   REFERENCES record_files(id)  ON DELETE SET NULL,
    CONSTRAINT fk_fixres_record FOREIGN KEY (record_id) REFERENCES records(id)        ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================================
--  OBJECTIVE 2 — METS
--  The wrapper binding description + preservation + files into one package.
-- ============================================================================

CREATE TABLE mets_packages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    record_id       INT NOT NULL UNIQUE,
    objid           VARCHAR(190) NOT NULL,
    profile         VARCHAR(500) NOT NULL DEFAULT 'https://sjp2cd.edu.ph/mets/profiles/etd-v1',
    label           VARCHAR(500) NULL,
    mets_type       VARCHAR(80)  NOT NULL DEFAULT 'ETD',
    record_status   VARCHAR(40)  NOT NULL DEFAULT 'COMPLETE',
    agent_name      VARCHAR(255) NOT NULL DEFAULT 'St. John Paul II College of Davao',
    agent_role      VARCHAR(40)  NOT NULL DEFAULT 'CREATOR',
    agent_type      VARCHAR(40)  NOT NULL DEFAULT 'ORGANIZATION',
    create_date     DATETIME NULL,
    last_mod_date   DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_mets_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE mets_files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    record_id     INT NOT NULL,
    file_id       INT NULL,
    mets_fileid   VARCHAR(60) NOT NULL COMMENT 'FILEID referenced by structMap fptr',
    file_grp_use  ENUM('ARCHIVE','ACCESS','SUPPLEMENT') NOT NULL DEFAULT 'ARCHIVE',
    mimetype      VARCHAR(120) NULL,
    size_bytes    BIGINT NULL,
    checksum      VARCHAR(128) NULL,
    checksum_type VARCHAR(20) NOT NULL DEFAULT 'SHA-256',
    href          VARCHAR(500) NULL,

    KEY idx_metsfile_record (record_id),
    CONSTRAINT fk_metsfile_record FOREIGN KEY (record_id) REFERENCES records(id)      ON DELETE CASCADE,
    CONSTRAINT fk_metsfile_file   FOREIGN KEY (file_id)   REFERENCES record_files(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE mets_divisions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    record_id    INT NOT NULL,
    parent_id    INT NULL,
    div_order    INT NOT NULL DEFAULT 0,
    div_level    INT NOT NULL DEFAULT 1,
    div_type     VARCHAR(40) NOT NULL DEFAULT 'div',
    div_label    VARCHAR(255) NOT NULL,
    file_pointer VARCHAR(60) NULL,
    page_start   INT NULL,
    page_end     INT NULL,

    KEY idx_metsdiv_record (record_id),
    CONSTRAINT fk_metsdiv_record FOREIGN KEY (record_id) REFERENCES records(id)       ON DELETE CASCADE,
    CONSTRAINT fk_metsdiv_parent FOREIGN KEY (parent_id) REFERENCES mets_divisions(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================================
--  OBJECTIVE 3 — communication that keeps the workflow moving
-- ============================================================================

CREATE TABLE notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    record_id  INT NULL,
    type       VARCHAR(50) NOT NULL DEFAULT 'info',
    title      VARCHAR(190) NOT NULL,
    message    TEXT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_notif_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    sender_id   INT NOT NULL,
    receiver_id INT NOT NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    read_at     DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_msg_pair (sender_id, receiver_id, created_at),
    KEY idx_msg_unread (receiver_id, is_read),
    CONSTRAINT fk_msg_sender   FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE typing_status (
    user_id       INT NOT NULL,
    typing_with   INT NOT NULL,
    last_typed_at DATETIME NOT NULL,

    PRIMARY KEY (user_id, typing_with),
    CONSTRAINT fk_typing_user FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_typing_with FOREIGN KEY (typing_with) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    record_id  INT NULL,
    action     VARCHAR(120) NOT NULL,
    details    TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_activity_created (created_at),
    CONSTRAINT fk_activity_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE SET NULL,
    CONSTRAINT fk_activity_record FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE SET NULL
) ENGINE=InnoDB;
