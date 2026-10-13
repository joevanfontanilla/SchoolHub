# UI/UX and Accessibility

## Design Goals
- Professional school-administration appearance
- Clear navigation
- Minimal clutter
- Responsive desktop/tablet/mobile layouts
- Consistent forms and tables
- Clear status indicators
- Accessible error messages

## Dashboard Pattern
Use:
- Summary cards
- Recent activity
- Important alerts (as built: urgent announcements as a dismissible banner on every portal
  page, with the word "Urgent" and an icon, `role="alert"`; docs/16)
- Tables
- Filters
- Search
- Pagination
- Quick actions

## Forms
- Clear labels
- Required-field indication
- Inline validation
- Helpful error messages
- Confirmation before destructive actions (as built: before every approve, reject, delete, and similar decision; see below)

## Accessibility
- Keyboard-friendly navigation
- Semantic HTML
- Proper labels
- Sufficient contrast
- Visible focus states
- Avoid color-only status communication
- Responsive text and controls
- Alt text for meaningful images

## Theme
Support light/dark mode if practical, while preserving readability and contrast.

## As built: portal conventions

- **Navigation** is defined once per role in `app/Helpers/navigation.php` and used by both the
  sidebar and the quick search. No placeholder ("Soon") items: every entry is a working page.
- **Quick search** in the top bar (press `/`): typing lists this portal's pages that match by
  name or by everyday words ("receipt" finds Payments & Accounts and My Documents); arrow keys
  and Enter open one. The Registrar, Cashier, and Super Admin can also search students,
  student accounts, or users from it. It is an ARIA combobox with a listbox of options.
- **Dashboard cards** open the list behind their number (for example "Awaiting confirmation"
  opens the queue filtered to it). Long values wrap instead of being cut off.
- **Preview window** for documents (docs/10): a `<dialog>` with a visible title, keyboard
  navigation (Escape, arrow keys for Previous/Next), and focus returned to the link.
- **Layout rules** in `src/input.css`: an element with the `hidden` attribute is always hidden,
  whatever display class it has; every grid cell may shrink below its content, so a wide table
  scrolls inside its own container instead of widening the page on a phone.
- **Empty states** show an icon and a sentence saying what will appear there.
- **Image uploads preview before submit.** Every image file input (profile photo, student photo set
  by the registrar, announcement image, school logo, payment proof) sits in a `data-image-field`
  wrapper with a `data-image-preview-target` image; picking a file shows it at once, so the user
  confirms the picture before clicking upload. A non-image (e.g. a PDF proof) reverts to the saved
  image or the placeholder. Without JavaScript the upload still works, just without the live preview.
- **The top-bar avatar** shows a student's profile photo when they have uploaded one, in place of
  their initials (`StudentProfileService::currentPhotoUrl`); other roles, and students with no photo,
  keep the initials tile.
- **Password fields** (sign-in, registration, reset, change password) have an eye button to show or
  hide what was typed: a real button with an accessible name that changes between "Show password"
  and "Hide password" and `aria-pressed`; the field is hidden again before the form is sent. A
  "Caps Lock is on" notice appears while typing with Caps Lock on. Without JavaScript the button is
  not shown.
- **Sign-in page:** two panels on wide screens (the campus photo with what the portal offers, and the
  form), one on phones; field icons; the submit button reads "Signing in…" while the request runs.
- **One design for every alert.** The confirmation window, flash messages, and the urgent banner
  share the same card: a colored icon tile, a word saying what kind of message it is, then the
  text. Tones are danger (reject, delete, disable, reverse, drop, cancel), warning (return, ask for a
  correction, override, archive, unlock, settings), success (approve, verify, confirm, publish,
  record), and primary (anything else). Flash messages can be dismissed.
- **Every deciding button asks first** in the shared confirmation window
  (`partials/confirm-dialog.php`), never the browser's `confirm()` box. On a form use
  `data-confirm="…"`; on one of several buttons use `data-confirm-click="…"` on that button. Optional:
  `data-confirm-title` (a question; otherwise the leading question of the message is used),
  `data-confirm-tone`, `data-confirm-label` (the confirming button's words), and
  `data-confirm-requires="#field"` with `data-confirm-requires-message` for a required reason: with the
  field empty the window says "Add a reason first" and puts the cursor in the field, and nothing is
  sent. The window is a modal `<dialog>` titled by its heading; Escape or "Go back" cancels; a danger
  action starts on "Go back". Accepting sends the form with the button that was pressed (its name,
  value, and `formaction`). Without `<dialog>` support the browser's own confirmation is used.
  Signing out asks too ("Sign out?"), from the sidebar and the account menu. Routine saves and
  reversible housekeeping (mark read, archive a notification) do not ask. `tests/confirmation_test.php` fails when a deciding button has no confirmation.

## Filters apply by themselves (as built)

Every filter and search form (any `<form method="GET">` in the views, except the top-bar "go to a page" search,
which is navigation) carries `data-auto-submit`, and `public/assets/app.js` makes it work without a button:

- A **dropdown, checkbox, radio, or date** applies as soon as it changes.
- A **search or number box** applies about half a second after typing stops. The page reloads, and the cursor
  is put back at the end of the same box so typing can continue (`sessionStorage`; if storage is blocked the
  filter still applies, only the cursor is not restored). Enter still applies at once.
- A **date range** (`from` and `to`) waits until it is valid: if the start date is after the end date, nothing
  is submitted and an inline alert says "The start date must be on or before the end date." The server's
  fall-back message is never reached from the form.
- Changing a filter resets `page` to 1.
- The Show / Search buttons are **hidden while scripts work**. Without scripts they stay in the page, so
  filtering still works with a click (the markup always contains them). Print, Export, and Clear are links
  and stay.

`tests/ui_filters_test.php` fails when a new filter form is added without `data-auto-submit`.
