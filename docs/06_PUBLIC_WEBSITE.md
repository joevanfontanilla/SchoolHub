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

## Contact
Show official school contact information and office details.

## Requirements for admission (editable, migration 049)
The public **Requirements** page (`/requirements`) shows the school's printed list of admission requirements, grouped as the school hands it out: Basic Education,
Senior High School (Grade 11 & 12), College (First Year, Transferees), and "If Married". The Super Admin edits it in **System Settings > Admission requirements**
(`settings.manage`): each **group** has a heading and one requirement per line, in order, and a note such as "(submit after 30 days)" is simply part of the line. Clearing a
group's heading and lines removes it, and a blank group at the bottom (or **Add another group**) adds one. A group needs a heading, there are at most 20 groups of 40
lines, and every save is audited (`requirements.published`). It is stored as one JSON setting (`public_requirements`) and starts with the school's flyer. This is
**information for the public**: it does not change what an enrolled applicant is asked to upload (docs/10).
