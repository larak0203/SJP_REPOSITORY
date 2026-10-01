# Gap analysis — paper vs. built system

**Paper:** *Web-Based Academic Research Repository System* — Kevin Lararesty Santos
**Adviser:** Gilbert D. Carnice, LPT · **Title page date:** May 2027
**Analysed:** 7 September 2026, against the system at `C:\xampp\htdocs\sjp2cd-repository`

No code has been changed. This document exists so the decisions below are yours.

---

## 1. What already matches

The foundations are sound. These need no work.

| Paper says | System does |
|---|---|
| The three objectives (upload/storage/management · DC+PREMIS+METS · intuitive UI) | Implemented and verified end to end |
| Dublin Core descriptive metadata | 15 elements as first-class indexed columns |
| PREMIS preservation metadata | All four entities: objects, events, agents, rights |
| METS structural metadata | Real packages with dmdSec/amdSec/fileSec/structMap |
| File integrity verification via checksum | SHA-256 at ingest, plus an audit that re-hashes and detects corruption |
| Role-based access: admin, faculty, student | Exactly these three roles |
| Search and browsing | Faceted browse by type, department, year + keyword search |
| PDF/DOCX up to 50 MB | Enforced |
| PHP · MySQL · Apache · XAMPP · phpMyAdmin · VS Code · Chrome | All correct |
| **Limitations:** no format migration, no full OAIS compliance | Correctly absent — the paper is honest here |

---

## 2. The paper promises things the system does not do

Each of these is a claim a panel can test. Effort is my honest estimate.

| # | Promised in | What is missing | Effort |
|---|---|---|---|
| 1 | Scope & Limitation | **"Research documents older than five years will be automatically moved to an archive section."** No such rule exists. The `archived` status and `archived_at` column exist but nothing applies them automatically. | **Small** — half a day |
| 2 | Comparison Matrix | **OAI-PMH compliance ✓.** Not built. It is currently listed as an explicit non-goal in `SPEC.md`. | **Medium** — 2–3 days |
| 3 | Comparison Matrix + Conceptual Framework | **Full-text search.** The framework says the system "indexes all content for full-text searching". It searches metadata only — not the words inside PDFs. | **Large** — needs a PDF text-extraction library that is not installed |
| 4 | Conceptual Framework | **Browse by author.** The paper lists author, department, year and type. Author browsing does not exist. | **Small** |
| 5 | Comparison Matrix | **Built-in analytics dashboard.** Statistics are scattered across dashboards; there is no dedicated analytics or reports page. | **Medium** |
| 6 | Figure 3 | **Quick Actions panel** on the dashboard (Submit New Research · Browse Repository · My Submissions · Review Pending). Does not exist. | **Small** |
| 7 | Figure 3 | **The four stat cards.** Paper: Total Documents · Approved · Pending Review · Total Downloads. System shows different figures. | **Small** |
| 8 | Figure 2 | **"Login as" role dropdown** (Admin / Faculty / Student) on the sign-in form. Not present — role comes from the account. | **Small** — see the caution in §5 |
| 9 | Figure 6 | **Manage Submissions** with All/Pending/Approved/Rejected filter tabs and Approve · Reject · Set Pending · Delete per row. The current admin page does different things. | **Medium** |
| 10 | Figures 3, 6 | **"Dissertation"** as a document type. Not in the current list. | **Trivial** |

---

## 3. The system does things the paper never mentions

Because the paper is still editable, each of these is either **document it** or **remove it**. Leaving them undocumented is the one option that hurts you — a panel asking "where is this in your paper?" is a bad moment.

| Feature | Origin | My recommendation |
|---|---|---|
| **Adviser approval stage** (student → adviser → library) | My suggestion, agreed in an earlier session | **Document it.** It is a genuine strength and matches how a college actually works — but see §4, it contradicts the paper's current workflow |
| **Live chat / messaging** with typing indicators | My suggestion | **Remove, or document as an enhancement.** It is the feature least connected to your three objectives |
| **In-app notifications** | My suggestion | Document briefly — it supports Objective 3 |
| **Dark-first theme, 3D motion, animated hero** | My suggestion | Document as interface design work under Objective 3 |
| **Google Sign-In scaffolding** | Your request, currently switched off | **Remove before submission** unless you configure and test it |
| **Fixity audit page** with pass/fail history | My suggestion | **Document it.** This is your strongest Objective 2 evidence |
| **METS XML export endpoint** per record | My suggestion | Document it — it makes the METS claim demonstrable |
| **Access levels** (open / campus / restricted) and **embargo** | My suggestion | Document — it strengthens the rights story |
| **Public Standards and Preservation pages** | My suggestion | Document — they explain Objective 2 to a reader |

---

## 4. Direct contradictions — these need a decision

These are places where the paper and the system disagree outright.

### 4.1 The workflow — the most significant

- **Paper (Figure 6, Conceptual Framework):** three states — **Pending → Approved / Rejected** — and the **Admin** moderates content directly. There is no adviser stage anywhere in the paper.
- **System:** eight states (draft, submitted, under_review, revision, approved, published, rejected, archived) with a **three-stage** workflow: student → adviser → library.

They cannot both be true. The system's version is richer and more realistic; the paper's is simpler and is what you have already documented.

### 4.2 Light vs dark

- **Paper:** every mockup is **light** — white content area, navy sidebar, navy top banner.
- **System:** **dark-first**, with light available on a toggle.

A panel comparing Figure 3 to the running system will see two different products.

### 4.3 The login page is mirrored

- **Paper (Figure 2):** form on the **left**, navy branding panel on the **right** with a welcome message and four feature icons (Upload Research · Search & Browse · Preserve Works · Track Impact).
- **System:** campus photo on the **left**, form on the **right**.

### 4.4 Bootstrap

- **Paper (Table 2):** lists **Bootstrap** as a software requirement.
- **System:** hand-written CSS. No Bootstrap anywhere.

### 4.5 Departments

- **Paper mockups:** College of Agriculture · College of IT · College of Engineering · College of Education · College of Arts & Sciences.
- **Title page:** you are from the **College of Information and Communications Technology**.
- **System:** CCS, CED, CBA, CN, CAS, CENG, CPSY, CCRIM, CACC — no CICT, no Agriculture.

### 4.6 Two things in the paper itself

- **Title page says "May 2027"** but you told me the defence is within a month.
- **"Bachelor of Service in Information Technology"** — almost certainly should read *Bachelor of Science*.
- Your adviser's comment on the document: *"Wala jud nag follow oh. Asa ang spacing na 1.5 ani"* — the 1.5 line spacing was not applied. That is a formatting fix in Word, not a system change.

---

## 5. Where I would push back

Two of the paper's promises are worth reconsidering rather than building.

**The "Login as" dropdown (Figure 2).** Letting a user pick their role at sign-in is poor practice — the role belongs to the account, not to a choice made at the door. If it stays, it must be cosmetic: the server still decides. Some panels will spot this and ask. My recommendation is to remove it from the paper rather than build it.

**Full-text search inside PDFs.** This is the single largest item on the list and needs a library you do not have installed. Options: build it properly with an extraction step at upload, soften the claim to "full-text metadata search", or move it to future work. It is currently claimed with a ✓ in your comparison matrix against DSpace and EPrints, which both genuinely have it.

---

## 6. What I would do, in order

1. **Resolve the workflow contradiction** (§4.1) — everything else depends on it
2. **Auto-archive after five years** (§2.1) — small, and it is a scope promise
3. **Dashboard: stat cards + Quick Actions** (§2.6, §2.7) — small, and Figure 3 is what a panel sees first
4. **Manage Submissions to match Figure 6** (§2.9)
5. **Browse by author** (§2.4) — small
6. **Decide on OAI-PMH and full-text search** — build, or soften the matrix
7. **Update the paper** to document the features in §3 that survive
