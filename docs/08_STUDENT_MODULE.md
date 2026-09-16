# Student Module

## Dashboard
Display:
- Current enrollment status
- Academic year/semester
- Program/year/section
- Outstanding balance
- Recent announcements
- Notifications
- Quick actions

## Profile
- Student number (issued when enrollment is confirmed; shown as pending until then)
- Full name
- Date of birth
- Contact details
- Address
- Guardian/emergency contact
- Program/course
- Year level
- Profile photo

## Student Navigation
- Dashboard
- Profile
- Enrollment
- Requirements
- Status
- Schedule
- Grades
- Attendance
- Fees
- Payments
- My Documents
- Announcements
- Notifications
- Password & Security

## Profile photo (as built)
The student may upload an optional profile photo on `/student/profile` (a small JPG or PNG,
up to 2 MB). It goes through `DocumentUploadService::assertGenuineFile()` — the same
extension + MIME + magic-byte check as every upload — so a disguised file is refused with the
error shown on the photo field, leaving the rest of the form intact. The image is stored under
`storage/uploads/profile-photos/` with a random name (never web-reachable), a new upload
supersedes and deletes the old file, and the "Remove my current photo" box clears it. It is
served only through `/student/photo` (the student's own) and `/registrar/students/{id}/photo`
(staff with `students.view`), streamed inline via `StudentProfileService::streamPhoto()` with the
MIME derived from the stored extension; both 404 when there is no photo. The registrar sees the
photo on the student record and can upload, replace, or remove it as part of a record correction
(`StudentRecordService::update`), audited as `student.record_corrected`. The upload control is one
partial (`partials/student/photo-field`) and the display one component (`components/avatar`), shared
by the student's form, the registrar's form, and the record header. Only that the photo changed is
audited, never the path. The upload shows a live preview before saving, and once a student has a
photo it appears in the top-bar account menu in place of their initials.

### Every user has a photo — `/account` and `AvatarService`
Staff have no student record, so their photo lives on `users.avatar_path` instead. `AvatarService`
is the single entry point the top bar and the shared **My Account** page (`/account`, every signed-in
role) use: it dispatches by role — a student's photo is still their record photo on
`student_profiles.photo_path` (settable by the student and the registrar, never duplicated onto
`users`), and every other role keeps theirs on `users.avatar_path`. `AvatarService::save()` runs the
same `assertGenuineFile()` check, stores under `storage/uploads/profile-photos/` (student) or
`storage/uploads/avatars/` (staff) with a random name, supersedes and deletes the old file, and
audits `user.avatar_updated` / `user.avatar_removed` by nothing but the fact of the change. The photo
is served only through `/account/photo` (the signed-in user's own, 404 when none), with the same live
preview before saving; it replaces the user's initials in the top-bar account menu for every role.
The account page also links to Change password.

## My Documents (as built, `/student/documents`)
Everything the student uploaded or was issued, in one place, each opening in the preview
window (docs/10): the files uploaded for each application (drafts included) with their review
status; official receipts; statements of account; the class schedule of each enrolled term;
and the proofs of payment they sent with their verification status. The page takes no record
id, so only the signed-in student's documents can appear (`StudentDocuments::forStudent()`).
Receipts, statements, and schedules open as printable pages inside the window, with Print.

## Document requests (as built)
A student requests official documents from the registrar on **Document Requests**
(`/student/credentials`, `credentials.request`): Transcript of Records, Certificate of Good Moral
Character, Certificate of Enrollment, or a copy of grades, with a purpose and number of copies.
Only one open request per document type at a time. The registrar works the queue at
`/registrar/credentials` (`credentials.manage`), moving each request REQUESTED → PROCESSING →
READY → RELEASED (or REJECTED with a reason), optionally noting a fee to settle at the cashier; the
student is notified at every step and can cancel while it is still pending. All through
`CredentialRequestService`, audited; a request is never deleted, only cancelled or rejected. (The
fee is informational here — it is not yet a finance assessment.)

## Certificate of Registration (as built)
An enrolled student can open a **Certificate of Registration** (COR) from the Account page — the
official one-page proof of enrollment for the term: the enlisted subjects with their schedule,
room, section and total units, and the fee assessment (fees, adjustments, amount due, payments,
balance). It opens in the preview window and prints. The registrar opens the same COR from the
student record. Both are assembled by `RegistrationDocumentService::certificate()` from the
enrollment and finance data, so they always match; a term with no assessment yet still prints the
subjects. Routes: `/student/cor` (own, `enrollment.view`) and `/registrar/students/{id}/cor`
(`students.view`).
