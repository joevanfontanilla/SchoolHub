# Requirements and Document Management

## Requirement Definition
Each academic enrollment period can have a configurable list of required documents.

Examples:
- Birth Certificate
- Report Card
- Good Moral Certificate
- Transfer Credential
- Identification
- Photo
- School-specific forms

## Requirement Statuses
- NOT_SUBMITTED
- SUBMITTED
- UNDER_REVIEW
- VERIFIED
- REJECTED
- CORRECTION_REQUIRED

## Rules
- Each uploaded file must have an allowed extension and size limit.
- Files must be stored outside publicly executable locations.
- Original filename must not be trusted as a storage filename.
- Registrar can verify/reject requirements.
- Students can see status and registrar remarks.

## Document List (as seeded)
Requirement types are records in `requirement_types`, not code. Each carries who it applies
to (`All`, or specific student types) and whether it is required; a term can override
"required" through `term_requirements`. When an application is created, the list for that
student type and term is copied onto the application as a snapshot.

| Document | Applies to | Required |
|---|---|---|
| PSA Birth Certificate | New, Transferee | Yes |
| Form 138 / Report Card | New | Yes |
| Certificate of Good Moral Character | New, Transferee | Yes |
| Transfer Credential (Honorable Dismissal) | Transferee | Yes |
| Transcript of Records | Transferee | Yes |
| 2x2 ID Photograph | All | Yes |
| Valid Government or School ID | All | No |
| Medical Certificate | New, Transferee | No |

## Upload Rules (implemented)
- **Types:** PDF, JPG/JPEG, PNG, as allowed per requirement type.
- **Size:** 5 MB per file by default (`max_file_size_kb`, per requirement type).
- **Content checks:** the extension must be allowed; the MIME type detected from the file's
  contents (`finfo`) must match that extension; the file must begin with that format's
  signature (`%PDF-`, JPEG `FF D8 FF`, PNG `89 50 4E 47`); images must also parse with
  `getimagesize()`. A text file renamed `.pdf` or a PNG renamed `.pdf` is rejected.
- **Storage:** `storage/uploads/requirements/{application_id}/{random 32-hex}.{ext}`, with a
  SHA-256 checksum. The uploaded name is kept for display only.
- **Replacement:** a new upload marks the previous file `is_current = 0`. Files are
  **superseded, never deleted.**
- **When uploads are allowed:** only while the application is `DRAFT` or
  `REQUIREMENTS_INCOMPLETE`. A document the registrar has `VERIFIED` cannot be replaced.
- **Access:** files are served only through `/documents/{id}`, which checks
  `DocumentAccessPolicy`: the owning student, the Registrar, and Super Admin. Teachers and
  Cashiers are always refused. Every view is audited; the audit entry never contains the
  file name or contents.
- Not implemented: virus scanning (no zero-dependency scanner exists). Mitigated by the
  content checks above, deny-all on `storage/`, a download by default with `nosniff`, and a
  preview that runs nothing (below).

## Document preview (as built)

Every uploaded document can be looked at without downloading it, in a **preview window** on
the page (`partials/preview-dialog.php`, opened by links made with `partials/preview-link.php`).

- **Where:** the student's requirement list and My Documents page; the registrar's application
  review (with **Previous / Next** through all of the application's documents) and student
  record; the cashier's payment verification, which shows the proof, PDF or image, on the page.
- **How it is served:** `/documents/{id}?inline=1` and `/payment-proofs/{id}?inline=1` go through
  the same authorization and audit as a download (`DocumentAccessPolicy`, `FinanceAccessPolicy`;
  another student's file is still a 404), but answer `Content-Disposition: inline` with a policy
  that lets **only this site** frame the file and lets **nothing run in it**:
  `X-Frame-Options: SAMEORIGIN` and
  `Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'`.
  Without `?inline=1` the file downloads, and every other response is still never framed.
  Only PDF, JPG, and PNG files exist (checked by magic bytes at upload), so nothing else can be
  shown inline.
- **The window:** a native `<dialog>` (Escape closes it, focus stays inside and returns to the
  link), with the document's name, Previous/Next when there are several, Download,
  Open in new tab, and Close. Images are shown directly; PDFs use the browser's own viewer.
  Some phone browsers cannot show a PDF inside a page, which the window says, pointing to
  Open in new tab and Download. Without JavaScript the link opens the file in a new tab.
