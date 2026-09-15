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
audited, never the path.

## My Documents (as built, `/student/documents`)
Everything the student uploaded or was issued, in one place, each opening in the preview
window (docs/10): the files uploaded for each application (drafts included) with their review
status; official receipts; statements of account; the class schedule of each enrolled term;
and the proofs of payment they sent with their verification status. The page takes no record
id, so only the signed-in student's documents can appear (`StudentDocuments::forStudent()`).
Receipts, statements, and schedules open as printable pages inside the window, with Print.
