# Announcements and Notifications

## Announcements
Fields:
- Title
- Content
- Category
- Image (optional)
- Target audience
- Publish date
- Expiration date
- Status
- Author

## Target Audiences
- Everyone
- Students
- Teachers
- Registrar
- Cashier
- Specific program
- Specific year level

## Notifications
Trigger examples:
- Enrollment submitted
- Requirement rejected
- Correction requested
- Enrollment approved
- Fee assessment generated
- Payment recorded
- Enrollment confirmed
- New announcement
- Password/security event

## Notification States
- UNREAD
- READ
- ARCHIVED

## Principle
Notifications should inform users without exposing information to users who are not authorized to see it.

---

## As built (Phase 6)

### Who writes and who reads
- **Writing** (`/manage/announcements`) needs `announcements.manage`; **publishing and archiving**
  also need `announcements.publish`. Both are held by the **Registrar**, and by **Super Admin**
  implicitly. Teachers, cashiers, and students only read. The service re-checks the permission of
  the acting user, not only the route.
- **Reading** (`/portal/announcements`) is open to every signed-in role, but lists only what the
  reader's audience includes, filtered in the query. Opening one outside your audience is a **404**.
  Managers can open drafts and scheduled ones as a marked preview.

### Fields
Title (5–200), text (plain text, 10–20,000 characters; a blank line separates paragraphs),
category (`General`, `Enrollment`, `Academic`, `Finance`, `Events`, `Advisory`), optional image
(JPG or PNG up to 2 MB, checked like every upload, stored outside the web root, with a required
description for screen readers), audience, **urgent**, **public website**, publish date, and end
date. Author and publisher are recorded.

### Audience (`Announcement::audienceSql`, the single definition)
- **Everyone**: every active user (a temporarily locked account counts; disabled and
  not-yet-activated accounts do not).
- **Chosen groups**: one or more roles. **For students only**, optionally narrowed by program(s)
  and/or year level(s): a student matches when their program is one of those chosen (if any are)
  **and** their year level is one of those chosen (if any are). Program and year level come from
  the student's **latest application that was not rejected or cancelled**, drafts included.
  Choosing programs or year levels without the Students group is refused.
- **Public website** is allowed only for "Everyone". Public announcements appear on
  `/announcements` for visitors; their images are served to visitors too.

### Lifecycle
| State | Meaning |
|---|---|
| Draft | Saved, invisible, nobody notified. May be deleted if it was never published. |
| Scheduled | Published with a future publish date. Invisible until then. |
| Published | Live: listed for its audience (and on the public website when public). |
| Expired | Past its end date. Hidden everywhere, still on record. |
| Archived | Taken down. Hidden everywhere. Can be restored to a draft. |

Anything that was ever published is archived, never deleted. Every create, update, publish,
archive, restore, delete, and delivery is audited (`announcement.*`).

### Notifications from announcements
When an announcement goes live, **every user in its audience gets one notification**
(`announcement.published`, linking to the announcement), written with a single
`INSERT … SELECT` over the same audience condition used for reading, so recipients and readers
never differ, **except that the author is not notified of their own announcement**. `notified_at` and a row lock make it happen **exactly once**; editing later, or
archiving and republishing, notifies nobody again. The number of recipients is shown to managers.

Scheduled announcements are delivered the first time any portal page loads after their publish
time (a plain XAMPP install has no cron). `php database/dispatch_announcements.php` does the same
and can be run from Windows Task Scheduler to deliver on time when nobody is signed in.

### Alerts: urgent banner
An announcement marked **urgent** is also shown as a red banner, with the word "Urgent" and an
icon, at the top of every portal page for its audience until it ends. Each reader can dismiss it
for themselves (`announcement_dismissals`); dismissing hides only the banner, not the
announcement. Urgent announcements are listed first and their notification title starts
"Urgent:".

### Notification inbox (`/portal/notifications`)
- The bell in the top bar shows the unread count and the five latest notifications, with
  "Mark all read" and a link to the inbox.
- The inbox has **Inbox** (unread and read), **Unread**, and **Archived** folders, 20 per page.
- **Opening** a notification marks it `READ` (with `read_at`) and follows its link. A link is
  followed only if it is an in-app path (one leading `/`, no scheme, no `//`, no backslash, no
  `..`); anything else just returns to the inbox, so a notification can never be an open
  redirect. The target page still applies its own authorization.
- **Archive** moves one to `ARCHIVED`; **Move to inbox** returns it to `READ`. Owners never delete
  notifications.
- Every action is on the signed-in user's own notifications; another user's is a **404**. All
  changes are POST with CSRF.

## As built: class announcements from teachers (`/teacher/announcements`)

A teacher posts news to **the students of their own classes**: a room change, a reminder, a
cancelled meeting.

- **Permission:** `announcements.class`, granted to Teachers by default (added to existing
  installations by `php database/seed.php`). The Super Admin can remove it in Roles & Permissions.
- **Audience `CLASSES`** (targets of type `CLASS`, one per class), part of the single audience
  definition: an active user **enlisted** (not dropped) in one of the chosen classes, or the
  teacher **currently assigned** to one of them. A student who drops the class stops seeing it; a
  teacher who takes the class over starts seeing it. A closed class reaches nobody.
- **Writing:** the teacher chooses one or more of their **own active classes** (anything else is
  refused), and writes the title, text, category, optional image, urgent flag, publish date, and
  end date. Class announcements are never public and cannot be widened to other audiences. The
  same lifecycle applies (draft, scheduled, published, expired, archived), and posting notifies the
  enlisted students once. An urgent class announcement shows the banner to those students only.
- **Who manages it:** only the teacher who wrote it edits, publishes, archives, restores, or
  deletes it; for another teacher it does not exist (404). The Registrar and Super Admin see class
  posts in `/manage/announcements`, marked "Class post by …", and may **archive** one (for
  example, if it is inappropriate) but not rewrite or restore it (403).
- **Readers** see "Students of IT 201 · BSIT-2A" on the announcement, so a student knows which
  class it is for.
- Every action is audited like any announcement (`announcement.*`, with the class ids).

### Not built
Email or SMS delivery (needs an outside mail service and the school's authorization),
system-generated action alerts on dashboards (for example "your requirement was rejected" as a
dashboard prompt, beyond the notification itself), security-event notifications (password changed,
account locked), and rich-text formatting. A notification about an announcement that was later
archived stays in the inbox; opening it shows "not available".
