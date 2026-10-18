# Curricula

A **curriculum** is one program's approved list of subjects, laid out by year level and
term, as issued by the Registrar. Lyceum of the East Aurora revises curricula (for
example after a CHED memorandum), and a student keeps the curriculum they were admitted
under, so a program can have several.

## The school's curricula

Transcribed from the Registrar's documents into `database/seeds/curricula/`:

| File | Code | Program | Effective | Subjects | Units |
|---|---|---|---|---|---|
| `bsa-2024.php` | `BSA-2024` | BS Accountancy | SY 2024-2025 | 61 | 182 |
| `bsais-2024.php` | `BSAIS-2024` | BS Accounting Information System | SY 2024-2025 | 60 | 179 |
| `bscrim-2022.php` | `BSCRIM-2022` | BS Criminology (CMO 4 & 5 s. 2018) | SY 2022-2023 | 64 | 194 |
| `bsentrep-2024.php` | `BSENTREP-2024` | BS Entrepreneurship | SY 2024-2025 | 48 | 144 |
| `bpa-2024.php` | `BPA-2024` | Bachelor of Public Administration | SY 2024-2025 | 54 | 161 |
| `bstm-2024-nonabm.php` | `BSTM-2024-NONABM` | BS Tourism Management, non-ABM strands | SY 2024-2025 | 55 | 164 |

Every curriculum has four year levels, two semesters per year, and a **summer term after
the second year**.

```bash
php database/seed.php                       # the six programs, and the Summer term
php database/import_curricula.php --check   # validate the files; writes nothing
php database/import_curricula.php           # import (skips any curriculum already present)
```

The importer never overwrites. Once a curriculum is in the database, the registrar
maintains it in the app (**Registrar → Curricula**); editing the data file afterwards has
no effect on it.

## Data model

- `curricula`: program, code, name, effective school year, notes, active.
- `subjects.curriculum_id`: the curriculum a subject belongs to. A subject with no
  curriculum is a **general subject**. `subjects.program_id` is kept equal to the
  curriculum's program.
- **Subject codes are unique within a curriculum, not school-wide.** The school reuses
  codes with different meanings: `CORE 1` is Financial Accounting and Reporting in BSA,
  Basic Accounting in BPA, and Macro Perspective of Tourism & Hospitality in BSTM.
- Codes may contain letters, numbers, spaces, dots, dashes, and underscores, and must
  start with a letter or number. They are stored upper-case with single spaces:
  `Pub Ad 2` is `PUB AD 2`.
- `subjects.lab_units`: how many of the subject's units are laboratory
  (Entrepreneurship's Business Plan Implementation is 5 units, 3 of them lab).
- `subjects.lecture_hours` / `lab_hours`: weekly contact hours, where the document gives
  them (only Criminology does).
- `subjects.min_progress_percent`: a **progress prerequisite**, used for internships:
  "80% of the course" means 80% of the curriculum's units must already be passed.
- `students.curriculum_id`: the curriculum the student follows.

## Rules

- A subject's curriculum is chosen when it is created and **never changes**. The choice is part of the New subject form, and it must be made:
  a curriculum, or **General** on purpose (no default). Coming from a curriculum page ("Add subject") preselects that curriculum.
- A subject in a curriculum must have a **year level and a term** (a General subject need not), so it lands in the right block of the
  curriculum at once instead of under "Unscheduled". An older subject without them can still be edited, but one that has them cannot lose them.
- After saving, the registrar lands on that curriculum, at the year and term the subject was placed in, and the unit totals are already updated.
- The prerequisite list on the form follows the chosen curriculum (its own subjects plus the general ones).
- A prerequisite must be a subject of the **same curriculum** or a general subject.
- In a curriculum file, a prerequisite must be taken in an **earlier term** (summer
  counts as after the second semester of its year). The importer refuses a file whose
  prerequisites are missing, in the same term, or later.
- The importer compares each term's units with the total printed on the document. A
  difference is reported as a warning, not an error: the document itself may be wrong.
- **Curriculum assignment.** At enrollment confirmation, a student with no curriculum,
  or one moving to another program, is placed under the program's active curriculum with
  the latest effective school year. A student already under a curriculum of the same
  program keeps it.
- **Unit load.** The most units a student may carry without an override is the larger of
  the `max_units_per_term` setting (27) and the regular load their curriculum prescribes
  for the section's year level and term. BSA and BSAIS first year, first semester is
  29 units, so block enlistment must not treat it as an overload.
- **Progress prerequisite.** A class whose subject has `min_progress_percent` is skipped
  in block enlistment, and needs an override reason to add, until the student has passed
  that share of the curriculum's units (rounded down).
- A curriculum's program cannot change once it has subjects or students.
- Curricula and subjects are deactivated by default. They can also be **deleted, but only when nothing refers to them** (the registrar's
  "Delete subject" and "Delete curriculum", `CatalogService::deleteSubject` / `deleteCurriculum`, permission `academic.manage`):
  - A **subject** is blocked by any class scheduled for it (with its grades and attendance), any student's subject selection, any
    credited subject, or being another subject's prerequisite. The button then lists what blocks it and points to Deactivate.
  - A **curriculum** is blocked by any student following it, or by any of its subjects being blocked as above (prerequisites between
    its own subjects do not count). An unused curriculum is deleted **together with its subjects**, and the page says how many.
  - It needs a reason (5+ characters) and the code typed to confirm, and is audited (`academic.subject_deleted`,
    `academic.curriculum_deleted`) with the code and title. It cannot be undone; the audit entry is what remains.

## Transcription decisions

These were needed to load the documents as printed. Each file lists them under
`to_confirm`, and `import_curricula.php` prints them.

**Normalisation applied everywhere:** codes upper-case (`Pub Ad 2` becomes `PUB AD 2`),
and abbreviated prerequisites written in full (`TOU 2` is `TOU 002`, `TOU 7` is `TOU 007`,
`GE 009` is `GE009`, `PATHFIT 1` is `PATH-FIT 1`, `prof 3` is `PROF3`, `Core 22` is
`CORE 22`).

### Open questions for the Registrar

| Curriculum | Question | Imported as |
|---|---|---|
| BSA, BSAIS | GE010 is used for both Environmental Science (1st year) and Peace Education (4th year). | Environmental Science is `GELEC1`, beside GELEC2 and GELEC3; Peace Education stays `GE010`, as in the other programs. |
| BSA | INTERN and RESEARCH are titled "Accounting Information System …" (apparently copied from BSAIS). | As printed. |
| BSA | PROF8 (Concepts and Applications 2) requires PROF2, not PROF4 (Applications 1). | As printed. |
| BSAIS | PRELEC is the code of two different subjects. | `PRELEC 1` Business Analytics, `PRELEC 2` Financial Modeling. |
| BSCRIM | 3rd year, 1st semester lists 24 units (23 lec \| 1 lab) but prints a total of 26 (24 \| 2). A subject may be missing. | The 24 listed units. |
| BSCRIM | Panitikan is coded `FL3`, beside FIL 1 and FIL 2. | As printed. |
| BSCRIM | PED4 has no prerequisite; PED2 and PED3 each require the previous one. | As printed. |
| BSCRIM | The Review requires "All professional courses". | Every CRIMINOLOGY, CLJ, CDI, LEA, CA, and FORENSIC subject from earlier terms. |
| BSENTREP | PATH-FIT 4 requires PATH-FIT 2; elsewhere it requires PATH-FIT 3. | As printed. |
| BSENTREP, BSTM | Christian Values is `CHRIST`; elsewhere `CHRIS1`. | As printed. |
| BPA | SOSCI 3 Macroeconomics requires `SOSCI 2`, which does not exist (perhaps ECO 1). | No prerequisite until confirmed. |
| BPA | GE008 is Ethics and GE009 is Rizal, the reverse of the other programs. | As printed. |
| BPA | Bookkeeping NC II; BSA and BSAIS have NC III. | As printed. |
| BSTM | ELEC 1 to 5 are placeholders for "the 11 free electives"; the list is not provided. | Placeholder subjects "Prof Elec 1–5". |
| BSTM | Only the non-ABM curriculum was provided. | ABM curriculum not loaded. |
| BSTM | Internship requires "75% of academic subjects completed". | 75% of the curriculum's units passed. |
| BSTM | POM requires TOU 002. | As printed. |

## Credited subjects (as built)

The registrar credits subjects to a specific student on the student record
(`/registrar/students/{id}`, permission `academic.credit`, `CreditedSubjectService`): transfer
credit, or subjects earned before the school moved onto this system (so a student already in
2nd, 3rd, or 4th year keeps the subjects they have passed). A credited subject counts as
**passed** — `AcademicRecordService::passedSubjectIds()` unions in the credited subject ids —
so it satisfies prerequisites and curriculum progress exactly as an earned final grade does.
The subject's units are snapshotted, an optional numeric grade and a free-text source
("Transferred from …") are kept, and every credit and removal is audited by subject code
(`student.subject_credited`, `student.subject_credit_removed`), never as a second copy of the
grade. A credit is refused for a subject already passed or already credited. This replaces the
prerequisite-override workaround for transferees and shiftees.

## Not yet supported

- Choosing a specific elective for a placeholder slot (BSTM ELEC 1–5).
- Changing a student's curriculum from the student record (for example, to an ABM
  curriculum). Today it is set only at confirmation.
- Mapping a credited subject onto a *different* subject code in the target curriculum
  (equivalency). A credit is recorded against the subject as it exists here.
- Showing curricula on the public Programs page.
