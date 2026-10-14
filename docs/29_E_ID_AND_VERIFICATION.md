# 29. E-ID and QR verification

Every enrolled student and every staff member has an **e-ID**: a card with their photo, name, ID number, and a
**QR code**. Scanning the QR opens a public page on the school's own website that says whether the ID is valid.
It is a convenience for checking a card against a person, not a government or legal ID.

## Who has one
- **Students** with a student number (assigned at enrollment). The ID number is the student number.
- **Staff**: teachers, registrar, cashier, administrators. Staff have no number today, so one is generated the
  first time the e-ID is issued, `EMP-yyyy-nnnnn` (`SequenceService`, key `staff_id`).

## The card
CR80 size (85.6 x 54 mm), printed one card per page, front then back.
- **Front:** school seal and name, photo, full name, ID number, program and year level (students) or role (staff),
  and the school year it is valid for.
- **Back:** the QR code, "Scan to verify", the school's address and phone, and the holder's e-signature if they have one.
- A person without a photo gets initials in place of one. The card prints even then; the registrar is told to add a photo.

## Who can open and print it
- The holder, from **My e-ID** in their own menu (`/e-id`, `/e-id/print`).
- The registrar, for any student (`/registrar/students/{id}/e-id`), with permission `students.manage`.
- The Super Admin, for any staff member (`/admin/users/{id}/e-id`), with permission `users.manage`.
Nobody else. A holder cannot reissue their own.

## The QR and the public page
- The QR holds `{app.base_url}/verify/{code}`. `app.base_url` must be the live site's https address
  (the deployment check already requires it). The code is 24 random hex characters stored in `e_ids.code`; it is **not**
  the student number, so IDs cannot be guessed or counted.
- `GET /verify/{code}` is public, with no sign-in. It shows a green **Valid** or a red **Not valid**.
  A valid result shows the photo, full name, ID number, program and year (or role), and the school year. It never shows
  a birthdate, address, phone, email, grades, or balances. An unknown code, a disabled account, and an expired ID all
  show the same "Not valid" page with no details, and the same 404 status, so a scan reveals nothing about *why*.
- The landing page has a **Verify an ID** box (`GET /verify`) where a code, or the whole scanned address, can be typed.
- `GET /verify/{code}/photo` streams that person's photo only while the code is valid.
- Pages are `noindex` and never cached.

## When an ID is valid
- **Student:** the account is active and the student has an `ENROLLED` enrollment in the **active school year**
  (`academic_years.is_active`) whose end date has not passed.
- **Staff:** the account is active.
- Anything else is **Not valid**. Disabling an account, or the school year ending, invalidates the ID with no extra step.

## Reissue
A lost card is **reissued**: a new code replaces the old one, so the old QR stops working at once. The ID number stays.
The registrar reissues for students and the Super Admin for staff; each reissue is audited (`eid.reissued`).

## Scan records and limits
Only a **count** and the **last scanned time** are kept (`e_ids.scan_count`, `last_scanned_at`): no IP, no location.
The public page allows about 30 lookups a minute per visitor (`e_id_lookups`, counted per IP and minute), so codes
cannot be guessed by trial. Too many lookups return 429.

## The QR generator
`App\Services\QrCode` is a small QR encoder written for this project (no library, per docs/00): byte mode, error
correction level M, versions 1 to 10, output as SVG. It is tested against the standard's reference values and decoded
back in the tests.