# Public Website

## Pages
- Home
- About
- Programs/Courses
- Enrollment
- Requirements
- Announcements
- Downloads
- Contact
- Login

## Home
Should include:
- School branding
- Hero section
- Enrollment call-to-action
- Announcements
- Programs
- School information
- Contact details

## Enrollment Page
Include:
- Enrollment dates
- Steps
- Requirements
- Eligibility information
- Frequently asked questions
- Application link

## Announcements
Show published public announcements with title, date, content, and optional image.

As built (Phase 6): only announcements for everyone that are marked **public website**, live
(published, due, and not ended), appear here, urgent ones first with an "Urgent" label. Their
images are served through `/announcement-images/{id}`, which checks the same visibility.

## Downloads
Allow administrators to publish public documents for download.

As built (migration 050): the Super Admin keeps this page under **Administration > Downloads** (`settings.manage`). The list shows every download with its title, file name,
size, how many times it was downloaded, who uploaded it, and whether it is shown publicly. The admin can **upload** a new file (title, optional description, order, shown or
hidden), **edit** the details, **replace** the file, **hide or show** it without deleting it, and **delete** it, which removes the file from the server after a confirmation.
The public page lists the shown ones by **order** (the smallest number first, newest first when equal).
- **Files:** documents only: PDF, Word (doc, docx), Excel (xls, xlsx), PowerPoint (ppt, pptx), and JPG or PNG images, up to 5 MB. The type is checked from the file's own bytes
  (a PDF starts with `%PDF`, an image must really be an image, an old Office file carries the Office container signature, a modern one is a zip holding the `word/`, `xl/` or
  `ppt/` folder), so renaming a program to `.pdf` does not get it in. Scripts, zips and anything else are refused.
- **Storage and links:** the file is kept under `storage/uploads/downloads` with a random name and is streamed by `/downloads/{id}`, which refuses a hidden download even to
  someone who has the link. The admin opens any file, hidden or not, from the list.
- **Audit:** `download.created`, `download.updated`, `download.replaced`, `download.shown`, `download.hidden` and `download.deleted`, none of them holding the file itself.

## Contact
Show official school contact information and office details.

## Requirements for admission (editable, migration 049)
The public **Requirements** page (`/requirements`) shows the school's printed list of admission requirements, grouped as the school hands it out: Basic Education,
Senior High School (Grade 11 & 12), College (First Year, Transferees), and "If Married". The Super Admin edits it in **System Settings > Admission requirements**
(`settings.manage`): each **group** has a heading and one requirement per line, in order, and a note such as "(submit after 30 days)" is simply part of the line. Clearing a
group's heading and lines removes it, and a blank group at the bottom (or **Add another group**) adds one. A group needs a heading, there are at most 20 groups of 40
lines, and every save is audited (`requirements.published`). It is stored as one JSON setting (`public_requirements`) and starts with the school's flyer. This is
**information for the public**: it does not change what an enrolled applicant is asked to upload (docs/10).
