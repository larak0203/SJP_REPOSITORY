# Changes to make in the paper

Apply these in Word yourself — your formatting, styles, numbering and your adviser's
comment stay intact. Each item quotes enough of the original to find with **Ctrl+F**.

Decisions already taken: keep the adviser stage and document it · switch the interface to
light to match your figures · soften the OAI-PMH and full-text search claims.

---

## A. Title page

### A1 — Degree

**Find:** `Bachelor of Service in Information Technology`
**Replace:** `Bachelor of Science in Information Technology`

### A2 — Date

**Find:** `May 2027`
**Replace:** *(pending — you said you'd confirm the Capstone 2 defence month)*

### A3 — Author line

The title page reads `By KEVIN LARARESTY SANTOS`. Your objectives paragraph says
**"The researchers will particularly aim to:"** — plural. If you are the sole author,
change it to **"The researcher will particularly aim to:"**.

**Find:** `The researchers will particularly aim to:`
**Replace:** `The researcher will particularly aim to:`

---

## B. Formatting — your adviser's comment

Your adviser left one comment: *"Wala jud nag follow oh. Asa ang spacing na 1.5 ani"* —
the 1.5 line spacing was not applied.

In Word: **Ctrl+A**, then Home → Paragraph → Line spacing → **1.5 lines**, and set
*Spacing After* to **0 pt**. Check the references list afterwards; APA hanging indents
sometimes shift when spacing is reapplied.

---

## C. Scope and Limitation — add the review workflow

Your Chapter 1 scope does not mention how a submission is approved. The system uses a
three-stage workflow, and a panel will ask about it.

**Find this sentence:**

> The system will feature role-based access for administrators, faculty, and students,
> along with search and browsing functionalities.

**Replace with:**

> The system will feature role-based access for administrators, faculty, and students,
> along with search and browsing functionalities. Submissions follow a three-stage review
> workflow: students deposit their work and it is routed to their assigned faculty
> adviser for approval; approved deposits are then released to the public catalogue by
> the library. Faculty deposit their own research directly to the library stage. Each
> decision is recorded with the reviewer, the stage, and the reviewer's comment, so the
> history of a deposit can be traced from submission to publication.

---

## D. Conceptual Framework — the Process stage

**Find this sentence** in the Process paragraph:

> The Submission Module processes file uploads, validates file formats, and captures
> metadata through form-based entry.

**Replace with:**

> The Submission Module processes file uploads, validates file formats, and captures
> metadata through form-based entry. It also routes each deposit through the review
> workflow, sending student submissions to the assigned adviser and approved works to the
> library for publication, while recording every decision made along the way.

**Also find**, in the same paragraph:

> The Search and Retrieval module indexes all content for full-text searching and enables
> browsing by author, department, year, and document type.

**Replace with:**

> The Search and Retrieval module indexes the descriptive metadata of all deposits for
> keyword searching and enables browsing by department, year, and document type.

*Why:* the system searches metadata — title, author, keywords and abstract — not the text
inside the PDF files. Claiming full-text indexing is a claim a panel can disprove in
about ten seconds by searching for a word that appears only inside a document.

---

## E. Comparison Matrix (Table 1)

Two rows currently claim features the system does not have. Both are checkable.

### E1 — OAI-PMH Compliance

Change the **Proposed Web-Based IR System** cell from **✓** to **✗**.

DSpace, EPrints and UP Diliman genuinely have OAI-PMH. Claiming it without building it is
the single most exposed item in your paper, because it is a published standard anyone can
test against a live endpoint.

### E2 — Full-Text Search

Either change the row label to **`Metadata Search`** and keep the ✓, or keep the label and
change the proposed cell to **✗**. I recommend renaming the row — it is accurate and you
keep the tick.

### E3 — The paragraph after the table

**Find:**

> It will also provide file integrity verification through automated checksum generation,
> ensuring that uploaded documents remain authentic and uncorrupted over time.

**Replace with:**

> It will also provide file integrity verification through automated SHA-256 checksum
> generation, together with an on-demand integrity audit that recomputes every stored
> digest and reports any file whose contents no longer match the value recorded at
> deposit, ensuring that uploaded documents remain authentic and uncorrupted over time.
> The system does not implement OAI-PMH metadata harvesting or full-text indexing of
> document contents; these are identified as directions for future development.

*Why the addition:* the audit is a genuine strength none of the three compared systems
demonstrate as plainly, and stating the two exclusions here protects you from the
appearance of overclaiming.

---

## F. Figure 6 — Manage Submissions

Your current description says the administrator approves and rejects directly. With the
adviser stage documented in §C, this needs to match.

**Find:**

> The Manage Submissions page is accessible exclusively to administrators and provides
> full control over the repository's content.

**Replace with:**

> The Manage Submissions page is accessible to faculty advisers and library staff, and
> provides control over the repository's content appropriate to each role. Faculty
> advisers see only the submissions of the students they supervise, while library staff
> see every deposit awaiting publication.

**Also find:**

> Action buttons are displayed based on the document's status: pending documents show
> Approve and Reject buttons, approved documents show a Set Pending option, and rejected
> documents can be re-approved.

**Replace with:**

> Action buttons are displayed according to the document's stage in the workflow. A
> submission awaiting adviser review shows Approve, Request Revision, and Return options.
> A deposit approved by an adviser shows Publish, which mints the permanent identifier and
> releases the record to the public catalogue. Published records can be archived, which
> withdraws them from public listing while preserving their metadata and files.

---

## G. Figure 3 — Dashboard

Your description lists four statistical cards: **Total Documents · Approved · Pending
Review · Total Downloads**. I am changing the system to show exactly these, so no edit is
needed here — but the screenshot must be retaken (see §I).

---

## H. Table 2 — Software Requirements

**Bootstrap is listed but is not used.** The interface is built with hand-written CSS and
no framework.

Either **delete the Bootstrap row**, or replace it with:

| Software | Requirements | Description |
|---|---|---|
| Custom CSS design system | Web browser, PC/laptop/tablet | A hand-written stylesheet defining the system's colour tokens, typography scale, spacing rhythm and components, built without a third-party framework to keep the interface lightweight and fully under the institution's control. |

I recommend replacing rather than deleting — "we wrote our own design system" is a
stronger answer than a framework you did not use.

---

## I. Figures to retake

All five prototype figures were captured from an earlier light-themed mockup. The system
is now light by default again, so the palette will match, but these screens have changed:

| Figure | What changed | Action |
|---|---|---|
| **2 — Login** | The page now shows the campus photograph and has **no "Login as" role selector** | Retake, and delete the sentence describing role selection — see §J |
| **3 — Dashboard** | Stat cards and Quick Actions are being rebuilt to match your description | Retake once I have finished |
| **4 — Submission Form** | Largely matches; the metadata badges need checking | Retake |
| **5 — Browse** | Filters are facet lists with live counts rather than dropdowns | Retake, and change "dropdown filters" to "filter panel" |
| **6 — Manage Submissions** | Now reflects the three-stage workflow | Retake after §F |

---

## J. Figure 2 — remove the role selector

**Find:**

> Users are required to enter their email address and password, and select their role as
> Admin (Library Staff), Faculty/Researcher, or Student. This role-based authentication
> ensures that each user is directed to the appropriate interface with the correct access
> permissions upon signing in.

**Replace with:**

> Users enter their email address and password. The role assigned to their account —
> Admin (Library Staff), Faculty/Researcher, or Student — determines which interface they
> are directed to and which permissions they hold, so no role selection is required at
> sign-in.

*Why:* letting a user choose their own role at the login screen is a weakness, not a
feature. The role belongs to the account and is decided by the server. A panel member with
a security background will ask what stops a student from selecting "Admin". This wording
turns a vulnerability into a correct design decision.

---

## K. Features to add to the paper

These exist in the system but appear nowhere in your document. Each is a question you do
not want to be asked cold. Suggested placement: a new subsection under **Prototype
Description**, or extra sentences in the Conceptual Framework's Output stage.

| Feature | Suggested sentence |
|---|---|
| **Integrity audit** | "The Preservation Module additionally provides an on-demand integrity audit that recomputes the checksum of every stored file and records the outcome, allowing the library to detect file corruption rather than discover it years later." |
| **Access levels and embargo** | "Each deposit carries an access level — open, campus-only, or restricted — and may be placed under embargo until a specified date, allowing authors to defer public release of the full text while the descriptive metadata remains discoverable." |
| **METS export** | "The complete METS package for any published record can be exported as XML, allowing the deposit to be transferred to another repository system without loss of description or structure." |
| **Notifications** | "The system notifies depositors and reviewers in-app when a submission changes state, so no deposit stalls unnoticed." |
| **Messaging** | *Optional.* If you keep the student–adviser messaging feature, describe it under Objective 3. If you would rather not defend it, tell me and I will remove it from the system. |

---

## Still pending from you

1. **The real SJP2CD colleges.** The Facebook link is restricted — Facebook returns "This
   content isn't available right now", and no Chrome session is connected for me to read
   it with. Please type the list.
2. **The Capstone 2 date** for §A2.
