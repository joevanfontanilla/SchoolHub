# 29. E-ID and QR verification

Every enrolled student and every staff member has an **e-ID**: a card with their photo, name, ID number, and a
**QR code**. Scanning the QR opens a public page on the school's own website that says whether the ID is valid.
It is a convenience for checking a card against a person, not a government or legal ID.

## Who has one
- **Students** with a student number (assigned at enrollment). The ID number is the student number.
- **Staff**: teachers, registrar, cashier, administrators. Staff have no number today, so one is generated the
  first time the e-ID is issued, `EMP-yyyy-nnnnn` (`SequenceService`, key `staff_id`).

## The card
Portrait CR80, **54 x 85.6 mm**, like the school's printed ID, printed one card side per page: front, then back (the page size is the card).

**Front.** A blue wave header with the school seal, the school name, and its address. The middle half of the front is **the photo of the school's building** (the campus picture used on the landing page,
`public/assets/images/campus.jpg`), with the photo of the holder (initials when there is none) over its left and a label with
**Student ID. No.** in red (a staff member's ID number) and the school year over its right. The name on a blue ribbon as `LAST, FIRST M.`, the program in capitals
(a staff member's role), and the holder's e-signature above a "SIGNATURE" line.

**Back, student.** Address, Birthday, and Blood Type (when filled in) on a blue block; "In case of emergency, please notify" with the emergency
contact's name, address, and number (the guardian marked as the emergency contact); a school-year by semester grid of four rows from the student's first
school year, printed blank for hand validation, and on screen with a check mark in each semester the student is enrolled in; the **QR code** with
"Scan to verify" beside "If found, please return to" the school and its address; the President's name, title, and signature; and a blue
**NON-TRANSFERABLE** bar. The personal details are on the printed card and on the holder's and registrar's screen only; the public page is unchanged.

**Back, staff.** The QR, the return line, the President, and the bar; no personal details and no grid (staff have no such profile data).

The President's name and title, and an optional signature image, are System Settings (School profile). The QR holds the same address as before, so a card
printed earlier still verifies.
- **Blood type** is an optional profile field (migration 046): A+, A-, B+, B-, AB+, AB-, O+, or O-.
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