# ADR 0020 — Files, documents and verification

* Status: **Accepted**
* Date: 2026-08-16
* Built in: TAB 15
* Extends: ADR 0004 (topology), ADR 0008 (database conventions), ADR 0016 (case engine),
  ADR 0018 (programme requirement templates)

---

## Context

Every requirement in this system eventually resolves to a piece of paper: a barangay certificate,
a medical abstract, a PhilSys card. Until now the backend could describe what was required and
not receive it.

Three acceptance criteria, all structural rather than procedural:

1. direct object-storage paths are not public;
2. a guessed file id cannot be downloaded;
3. replacing a document preserves the old version's metadata.

The admin console already models this domain in detail, derived from how the office actually
works, and that model is authoritative for the vocabulary here: `DocumentSource`,
`RequirementDocument` with append-only versions, `DocumentRequest`, and validity states that
distinguish "does not expire" from "expiry not recorded". The resident mobile client independently
arrived at the same upload limits. Where this ADR departs from either, it says so.

---

## 1. `Files` is a module with no routes

**Decision: `Files` publishes application services and nothing else. The module that owns a record
authorises access to that record's documents.**

A file store cannot answer the only question that matters at an HTTP boundary — *may this caller
see this document?* Welfare knows whether a caseworker is in the right barangay for a case; a
store knows only that it holds bytes.

The obvious alternative was a generic `GET /api/v1/files/{id}` guarded by a permission. A single
permission cannot express "this caseworker, this case, this barangay", so it would inevitably
resolve to *any staff member may read any document* — precisely the enumeration this system is
built to prevent.

So the shape is: the owning module authorises, then calls in. Calling
`DocumentLibrary::issueAccess()` **is** the assertion that a check has happened, which is why it
takes an actor rather than a permission. A module that forgets to check has written that
assertion falsely — a reviewable line in a controller, rather than a silent absence somewhere
else.

`Files` depends only on `Shared`, and must stay that way: every module above may store documents,
and a dependency in this direction would close a cycle with the first one that does.

### The boundary is DTOs, not models

`DocumentLibrary` is the single published entry point. Other modules speak UUIDs and receive
`DocumentVersionView` / `StoredFileView`. They never hold a Files Eloquent model.

Article 2.1 requires this. What makes it worth more than compliance is that it converts two
guarantees from habits into properties:

* a consuming module **cannot reach the bytes on its own** — no disk, no storage key, no path is
  published, so criterion 1 survives whatever gets built on top;
* a consuming module **cannot rewrite history** — there is no model to call `delete()` on, so
  criterion 3 does not depend on every future caller remembering it.

The first draft of this TAB passed Welfare an Eloquent `StoredFile` and read version fields
directly off the model. `ModuleBoundaryTest` caught it. The DTO layer is the fix, and it is
better code than what it replaced.

---

## 2. The server decides what a file is

**Decision: the accepted type is read from the file's own leading bytes. The declared
`Content-Type` and the filename extension are treated as decoration.**

Both are supplied by the caller, and both look correct on a file that is neither. `.pdf` holding
HTML, `.jpg` holding a script — the upload arrives well-formed and the metadata agrees with
itself.

`UploadedFile::getMimeType()` is not the answer either: it guesses from the extension in some
configurations and from content in others, depending on whether `fileinfo` is loaded. **A
security control that varies by deployment is not a control.**

Three types, from the office's own list and identical in both clients: JPEG, PNG, PDF. 10 MiB,
also identical. Both clients run the same signature check before uploading; **theirs is a
courtesy and this one is the boundary** — the resident mobile client says so explicitly in its
own source. A client-side check saves somebody a slow upload; it proves nothing about what
arrived.

**The storage key is generated, never derived from input.** A UUID plus the extension of the
*verified* type. A caller-supplied filename is caller-supplied path input, and `../` in a
filename is the oldest write-anywhere bug there is. The original name survives only as a display
string, sanitised, with its extension corrected to the truth.

### The 413 that never reaches the application

`AcceptedMediaType::MAX_BYTES` must stay **below** the reverse proxy's `client_max_body_size`.
If nginx rejects the body first it answers 413 without running PHP, therefore without CORS
headers, and a browser sees a network failure with status 0 rather than a message anybody can act
on. Two distinct error codes were added for this — `PAYLOAD_TOO_LARGE` and
`UNSUPPORTED_MEDIA_TYPE` — because the client's recovery differs: shrink it and retry, or give up
on this file entirely. Both clients already have fixed copy for each case.

---

## 3. Replacing is appending

**Decision: there is no `replaceDocument` and no `deleteDocument`. A replacement appends a
version and stamps the previous one.**

The superseded version is the evidence of what the office actually saw when it decided. A request
approved in March on the strength of a certificate replaced in June must still be explicable in
December — and an overwriting model makes that not hard but *unanswerable*.

A replacement carries a **mandatory reason**. An unexplained supersession leaves a version nobody
can account for, which is worse than having no history at all, because it looks like history.

Guarded three ways, because this is the guarantee most likely to die to a well-meaning tidy-up
(a `replaceDocument()` that overwrites, a `deleteVersion()` for a mistaken upload, a `forceDelete`
in a retention job — each reasonable in isolation):

* `DocumentHistoryIsAppendOnlyTest` scans for destructive verbs and for any `function replace…`;
* `document_versions` has **no `updated_at`** — an `updated_at` on an append-only table is an
  invitation, and the next reader will believe it;
* only `Files` can write the table at all, per §1.

The console guards the same invariant on its side with `check:documents`. This is the server half,
and it is the half that matters, because the console is one of four clients.

**Note on the scan's first run:** it reported `DocumentService` as a violation because that
class's docblock contains the sentence "there is no `replace()` and no `delete()` in this class".
It now strips comments via PHP's tokeniser before matching. A detector that cannot tell a
statement of the rule from a breach of it gets silenced rather than fixed, so that sentence is
now its own negative fixture.

---

## 4. The document number is masked before storage

**Decision: only the last four characters are ever written to a column, and only where the source
holds no file.**

The master command asks for a "masked document number". This takes it literally.

Masking at *render* time — what the console does today, applying `maskDocumentNumber` in the view
over a full `documentNumber` it was sent — leaves the value in every backup, replica, dump, query
log and support export, and rests the guarantee on every current and future reader remembering to
mask. Masking at *write* time means the full number was never kept, so no future endpoint can
leak it and no client can mishandle it. Recorded as gap **G-24**: the console should render the
masked value it is given.

Four characters is the whole purpose: a clerk confirming the paper on screen is the paper in their
hand. It cannot reconstruct an identifier — the same limit RA 11055 imposes on the PhilSys
reference, applied to every document type rather than only the one the law names. A number short
enough that masking would reveal most of it is not stored at all.

**And only where there is no file.** For an uploaded or scanned document the image *is* the
record, and storing the number as well is a second copy of a government identifier for no
operational gain — the reasoning that already keeps extracted numbers out of `kyc_documents`. For
`encoded` and `external-verification` there is nothing else: the number is the only thing
separating "we checked their PhilSys card" from "we checked something".

---

## 5. A grant, not a signed URL

**Decision: reading a document means redeeming a single-use, account-bound, two-minute grant,
streamed by this application.**

Article 8.5 permits either an authorization-gated endpoint or a short-lived signed URL. This picks
the endpoint, and the deciding reason is audit.

A signature is valid wherever it is pasted, for as long as it lasts, to whoever holds it — and
**nothing records that it was used**. Object storage does not tell this application when somebody
fetches an object. Article 5.4 requires every read of another person's personal data to be
auditable, so the read has to pass through here for `document.read` to be a row anybody can find.

The grant is also *bound to an account*, which a signature is not. A handle that leaks — a browser
history, a pasted chat line, a screenshot — is useless to whoever finds it. Unknown, expired,
spent and issued-to-somebody-else all answer **404**: distinguishing them would confirm which
handles were once real (OWASP API1, the rule the rest of this API already follows).

Consumption happens inside a locked transaction *before* the bytes are read, so two simultaneous
redemptions cannot both succeed.

The response is hostile to embedding and caching: `no-store`, `nosniff`, `X-Frame-Options: DENY`,
`Content-Disposition: attachment`, and the **verified** content type. A citizen's identity document
rendered inline in somebody else's page is a disclosure with no record of having happened.

Criterion 2 is then structural in two layers: there is no file id in the contract at all, and the
version must sit in the requirement slot named in the path — without that second check the case id
would be decoration and any version UUID would open from any case the caller can reach.

---

## 6. Requirements are copied onto a case, not read through

**Decision: `welfare_case_requirements` copies the programme's requirement template at intake,
with the version that produced it.**

A programme's requirement list is versioned and changes. Reading through to the live template
would silently rewrite what an applicant was asked for, so a case approved under three
requirements would later appear to have skipped a fourth that did not exist at the time. Same
reasoning as the pinned guidance version in ADR 0018.

**A conditional requirement waits for a human.** The software states the condition and never
evaluates it, because whether "the applicant is not the patient" holds is a judgement about a
person's circumstances. Deciding somebody does **not** need a document is exactly as consequential
as deciding they do — it is the step that can quietly waive a safeguard — so it carries an author,
a timestamp and a reason mandatory in both directions, and `undecided` is a real state that
surfaces as outstanding work rather than a default meaning "probably fine".

**`document_requests` is a separate table from case tasks**, because the obligations differ: a
task is late when *staff* are late, a request when the *applicant* is. A queue that mixes them
tells a supervisor nothing, because half of it is work nobody in the building can do. The
`message` — what the applicant was actually told, in the words used — is mandatory: an empty one
records that something was asked for without saying what, which looks like follow-up the office
cannot show.

A document arriving **closes the request that asked for it**. A clerk who has just recorded the
certificate should not also have to tick off the request, and a request left open after it was
answered is what stops an overdue queue being believed.

---

## 7. Four permissions, split where the consequence changes

| Permission | Holder | Why separate |
| --- | --- | --- |
| `document.manage` | `lgu_staff`, `lgu_admin` | Receiving papers at the counter is the job. |
| `document.verify` | `lgu_admin` only | "We received this" and "this satisfies the requirement" are two claims, and only the second advances a case toward money. The clerk who took the paper is not thereby the person who judged it sufficient. |
| `document.view.sensitive` | `lgu_admin` only | Knowing a safeguarding document exists and opening the image are different acts. Sits with the admin for now for the same reason as `vulnerability.view.protected`: the protection-officer role does not exist yet. |
| `document.share` | **nobody** | The outward-sharing path is built and refused rather than built and quietly granted. |

That last row is deliberate. Every internal read leaves a trail this system controls; a copy that
leaves does not. The first holder of `document.share` should be a decision the LGU makes on the
record, not a line that arrived with a feature (gap **G-26**). The code path, the classification
check and the scan-status check are all in place and tested — the permission is simply unheld.

**Receipt is not satisfaction.** `isSatisfied()` requires a current version that is *verified* and
*unexpired*. Either alone is insufficient: an accepted certificate that lapsed last month is still
accepted and no longer proves anything. Treating receipt as satisfaction would let a case reach
approval on papers nobody read.

---

## 8. The citizen surface

A citizen may upload only into their own permitted slots — held by the lookup rather than a check
afterwards: resident from the token, case from that resident, requirement from that case. Another
applicant's case id resolves to **404**.

What an applicant may not do, all deliberate:

* **verify** — uploading is presenting, not accepting; every citizen upload lands `pending`;
* **rule a conditional requirement in or out** — the safeguard-waiving step;
* **record an `encoded` or `external-verification` document** — both assert that staff saw or
  confirmed something. The source is forced to `uploaded` **from the route**, the same rule as the
  intake source in ADR 0017;
* **share outward**;
* **see the office's handling** — no reviewer, no scan status, no applicability reason, and the
  verification note only on a *rejection*, where it is the instruction for what to bring instead.
  On an acceptance it is an internal remark.

---

## 9. Scanning: `pending` is not `clean`

The master command asks for a "malware-scan status hook". The hook is complete — column, state
machine, queued job, failure path, and download consequences. The scanner is deployment
configuration (gap **G-25**).

`ScanStatus` is an enum rather than a boolean precisely because a two-state flag must default to
something and both defaults are wrong: default true and every unscanned file is treated as safe;
default false and every file is quarantined before the scanner has said anything.

* `Pending` — served to staff, refused for outward sharing. A caseworker opening an unscanned
  attachment on a managed workstation is a risk the office already took by accepting the upload;
  handing that file to a partner agency passes the risk to somebody else.
* `Skipped` — no scanner configured. Distinct from `Pending` so "nobody is going to scan this"
  cannot be mistaken for "the scan has not finished"; a queue of permanently pending files looks
  like a backlog when it is a missing scanner.
* `Infected` — served to nobody, by any route.

On repeated job failure the status stays `pending` with a detail note. Marking clean on failure
would be the worst possible default; marking infected would quarantine a file over an
infrastructure problem.

---

## Consequences

* Documents are versioned, explicable and impossible to overwrite through this API.
* No public path to an object exists anywhere in the codebase, and none can be built from what is
  published.
* Every read of a document is a row in the audit trail, because the bytes come through the
  application by design.
* Government identifiers are never stored in full.
* `kyc_documents` **predates this store** and still has its own table, scoped to a KYC case.
  It should adopt `Files` by expand → migrate → contract (gap **G-27**) — not folded in here,
  because migrating live document rows is its own change with its own risk.
* Retention is a classification-driven table with placeholder periods (gap **G-25**), reviewable
  in one file so approving it is a single small act.
