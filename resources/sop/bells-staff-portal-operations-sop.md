# Bells University Staff Portal — Standard Operating Procedure

**Document ID:** SOP-STAFF-PORTAL-001  
**Version:** 1.7  
**Effective date:** August 2026  
**Audience:** ICT administrators, registrars, office heads, and authorised staff  
**Classification:** Internal use only

---

## 1. Purpose

This Standard Operating Procedure (SOP) describes the Bells University staff portal as implemented: how access is controlled, how administrators configure the platform, and how day-to-day admissions, academic, and service modules are used.

---

## 2. Scope

This document covers:

- Staff portal sign-in, navigation, and profile management
- Role-based permissions and office-based sidebar scoping
- Department / office structure and portal link assignment
- User management and office placement
- Global application security settings (2FA, password rotation, inactivity logout)
- Academic catalogue, application windows, candidate lists, applicant import, and continuing-student import
- Applications and registrations pipelines
- Fees, clinic, hostel, documents, audit, and reports
- Office heads of department / unit heads and the Approvals inbox

**Out of scope:** Detailed student-portal self-service screens, payment-gateway merchant credentials, and server infrastructure.

---

## 3. System overview

| Component | Description |
|-----------|-------------|
| **Staff portal** | Web application for university staff |
| **Student portal** | Separate sign-in for applicants and enrolled students |
| **API** | Backend for authentication, permissions, and business logic |
| **Database** | Users, roles, office structure, settings, and operational records |

Staff and students use **separate portals**. An account is routed to the correct portal at sign-in based on assigned roles and staff records.

Applicants and students sign in on the **student portal** with **application number** (`APP/YYYY/#####`), **JAMB registration number**, or **matric number** and password. Email is used for mail only; password reset asks for the same sign-in ID and then emails the address on file.

---

## 4. Roles and permissions

### 4.1 Permission model

Access is controlled by **permissions** (for example `users.manage`, `admissions.view`). Permissions are grouped into **roles**. Users may hold one or more roles.

**Super Admin** (`super-admin` slug) has unrestricted access to all permissions and all sidebar links.

Staff need **both** the role permission **and** an office portal link for each sidebar item (except Super Admin).

### 4.2 Managing roles and permissions

| Task | Location | Required permission |
|------|----------|---------------------|
| View or edit roles | Administration → Roles | `roles.manage` |
| View permission catalogue | Administration → Permissions | `roles.manage` |
| Assign roles to users | Administration → Users | `users.manage` |

When creating or editing a role, tick the permissions that role should grant. Changes apply immediately to all users holding that role.

### 4.3 Standard roles (seeded)

| Role | Typical access |
|------|----------------|
| **Super Admin** | Full access to all permissions and sidebar links |
| **Registrar** | Academic setup, applications, registrations, reports |
| **Admissions** | Applications pipeline, application setup (programmes, windows, candidate data, applicant import), registrations view |
| **Finance** | Fees, invoices, payments |
| **Medical** | Clinic module |
| **Faculty** | Student view |
| **PG Coordinator** | Postgraduate records and applications view |
| **Hostel Officer** | Hostel module |

### 4.4 Applications and registrations permissions

| Permission | Purpose |
|------------|---------|
| `admissions.view` | View and process the Applications pipeline |
| `admissions.screen` | Advance files at screening |
| `admissions.verify` | Advance files at verification |
| `admissions.credit_assess` | Assess transfer credits |
| `admissions.shortlist` | Shortlist applicants |
| `admissions.recommend` | Recommend admission |
| `admissions.approve` | Approve admission |
| `admissions.offer` | Issue admission offer |
| `admissions.matriculate` | Matriculate students |
| `admissions.pg.screen` … `admissions.pg.panel` | Postgraduate-specific review steps |
| `admissions.import` | Upload JAMB candidate lists and import applicants from another portal |
| `registrations.view` | View Registrations (matriculated students with at least 25% current-session tuition paid) |

### 4.5 Academic setup permissions

Academic pages use **per-resource** permissions:

| Permission | Sidebar item |
|------------|--------------|
| `academic.campuses.manage` | Campuses |
| `academic.colleges.manage` | Colleges |
| `academic.departments.manage` | Departments |
| `academic.sessions.manage` | Sessions & semesters |
| `academic.levels.manage` | Levels |
| `academic.courses.manage` | Courses |
| `academic.programmes.manage` | Programmes |
| `academic.intakes.manage` | Application windows |
| `academic.olevel.manage` | O'level |
| `academic.offerings.manage` | Offerings |
| `academic.enrollments.manage` | Course registration and unit limits |
| `academic.extensions.review` | Registration extensions |
| `exam_clearance.view` | Exam clearance |

Legacy permissions (`institution.manage`, `academic.catalog.manage`) still grant access to matching setup areas for backward compatibility.

`institution.manage` is required for **Department Setup** (office hierarchy and portal links). The Institution administration route still exists for users with this permission, but it is not shown on the staff sidebar.

---

## 5. Staff sign-in and account security

### 5.1 Sign-in

1. Open the staff portal URL.
2. Enter work **email** and **password**.
3. If two-factor authentication (2FA) is enabled globally, complete the authenticator step after password verification.
4. On first login with 2FA enabled, scan the provided secret into an authenticator app and enter the 6-digit code.

Applicants and students must use the **student portal**, not the staff portal.

### 5.2 Password reset

1. On the sign-in page, click **Forgot password?**
2. Enter the registered email address.
3. Follow the link in the reset email to set a new password.

### 5.3 Profile

Staff open **Profile** from the account menu (top right). They can update name, phone, and password.

- Setting a new password requires the **current password** and a matching confirmation.
- Password rules: at least 8 characters, with uppercase, lowercase, a number, and a symbol; the password cannot match the email address.
- Success and error feedback appears as toast messages (not inline banners).
- Assigned roles and permissions are shown on the profile page.

If organisation-wide password rotation is enabled, staff who are overdue see a blocking prompt until they change the password (current password is required there as well).

Email changes require ICT support.

### 5.4 Global security policies (Application settings)

Users with `settings.manage` configure policies under **System → Application settings**. These apply to **all staff** immediately:

| Policy | Options | Effect |
|--------|---------|--------|
| **Two-factor authentication** | On / Off | When on, every staff member must use TOTP at sign-in |
| **Password rotation** | Off, 30, 60, 90, or 180 days | Staff must change password when the interval expires |
| **Inactivity logout** | Off, 15, 30, 60, or 120 minutes | Staff are signed out after idle time |
| **Studentship after graduation** | 1–10 years (default 2) | Years after registrar conferment before graduates become alumni and student-portal login is locked |
| **Admissions contact** | Email, phone | Shown on student login and signup |
| **Staff login support** | Label, email, phone | Shown on staff sign-in (default label: ICT & Registry support) |

**Operational notes:**

- Enabling 2FA affects all staff on their next sign-in.
- Password expiry shows a blocking prompt until a new password is set.
- Inactivity is enforced on both the browser and the server.

---

## 6. Sidebar navigation and office structure

### 6.1 How sidebar links are determined

For **non–Super Admin** staff, visible sidebar links depend on **office placement**, not permissions alone:

1. **Home** is always visible.
2. Other links appear only if assigned to the staff member's office node **and** the user has the permission for that link.

**Super Admin** sees all links they have permission for, without office restrictions.

When a section (for example Administration) contains a single dropdown whose label matches the section title, the section heading is hidden so the dropdown stands alone.

### 6.2 Current staff sidebar

| Section | Items |
|---------|-------|
| **Overview** | Home, Students, Approvals |
| **Applications** | Undergraduate, JUPEB, Postgraduate |
| **Registrations** | Undergraduate, JUPEB, Postgraduate |
| **Academic** | Admission Setup (dropdown), Application Setup (dropdown), Enrolment (dropdown), PG research, Exam clearance |
| **Services** | Fees & payments (dropdown), Clinic, Hostel, Documents |
| **Administration** | Users, Roles, Permissions, Department Setup |
| **System** | Audit, Reports, Announcements, Integrations, Application settings, Resources |

**Admission Setup** contains: Campuses, Colleges, Departments, Sessions & semesters, Graduation, Levels, Courses.

**Application Setup** contains: Programmes, Application windows, Candidate data, Import applicants, O'level.

**Enrolment** contains: Offerings, Course registration, Unit limits, Registration extensions, Import students.

**Results** contains: Results dashboard, Result entry, CSV import, Approvals, Board, Release, Grading scale. Grade changes appear under System → Audit (module `results`).

**Fees & payments** contains: Fee catalog, Sundry fees, Rebates, Programme fees, Generate invoice, Invoices, Students Financial Status, Import invoices, Import wallet history.

### 6.3 Office hierarchy

Offices are organised in three levels:

```
Office Department  →  Office Unit  →  Office Sub-unit
```

Example:

- Registry (department)
  - Student Records (unit)
    - Transcript Desk (sub-unit)

Configure structure under **Administration → Department Setup** (page title **Office setup**, route `/office-setup`).

### 6.4 Assigning sidebar links to an office

1. Go to **Department Setup**.
2. Locate the department, unit, or sub-unit row.
3. Click **Links**.
4. Tick the sidebar items that office should access.
5. Save.

Staff placed at that node will see only those links (plus Home), subject to their role permissions.

**Portal link keys** (used in Department Setup → Links):

| Staff label | Nav key | Route |
|-------------|---------|-------|
| Applications → Undergraduate | `admissions-undergraduate` | `/applications/undergraduate` |
| Applications → JUPEB | `admissions-jupeb` | `/applications/jupeb` |
| Applications → Postgraduate | `admissions-postgraduate` | `/applications/postgraduate` |
| Registrations → Undergraduate | `registrations-undergraduate` | `/registrations/undergraduate` |
| Registrations → JUPEB | `registrations-jupeb` | `/registrations/jupeb` |
| Registrations → Postgraduate | `registrations-postgraduate` | `/registrations/postgraduate` |
| Candidate data | `candidate-data` | `/academic/candidate-data` |
| Import applicants | `import-applicants` | `/academic/import-applicants` |
| Import students | `import-students` | `/academic/import-students` |
| Import invoices | `import-invoices` | `/finance/import-invoices` |
| Import wallet history | `import-wallet` | `/finance/import-wallet` |
| Department Setup | `office-setup` | `/office-setup` |
| Approvals | `approvals` | `/approvals` |

Academic setup items use the resource keys `campuses`, `colleges`, `departments`, `sessions`, `graduation`, `levels`, `courses`, `programmes`, `intakes`, `olevel`, `offerings`, `course-registration`, `unit-limits`, `registration-extensions`.

Legacy `/admissions/*` URLs redirect to `/applications/*`.

### 6.5 Assigning staff to an office

1. Go to **Administration → Users**.
2. Create or edit a user.
3. Set **office placement** (department, unit, or sub-unit). This is an administrative office (for example Admissions or Registry), not an academic faculty or programme.
4. Save.

If placement points to a deleted or invalid office node, the system clears stale placement on login and the user will see **Home only** until reassigned.

### 6.6 Clearing office placement

On the Users page, use **Clear office placement** to remove a staff member from all office nodes. They retain role permissions but lose scoped sidebar links (Home only, unless Super Admin).

### 6.7 Office heads and the Approvals inbox

Each office **department** can have a **head of department (HOD)**. Each **unit** can have a **unit head**. Assign these on **Department Setup** when creating or editing the row. The picker only lists staff who already work in that office. Users tagged **HOD** or **Unit head** appear on **Administration → Users**.

A sidebar module is **owned** by the office department that has that portal link. The same link cannot be given to two different departments.

When assigning a portal link on **Department Setup → Links**, modules with gated actions open a prompt for:

1. **Create / Update / Delete** — which mutation types require office approval (Update also covers workflow actions such as allocate, publish, board clear).
2. **Who must approve** — unit head only, department head only, or **both** (normal path: unit head → department head).

Defaults when checking a link: all three methods on, chain = both.

When a mutation requires approval for an owned module:

1. **Subunit staff** usually go to the parent **unit head** first (unless the chain is department-head only). If the unit has subunits and no unit head, the action is blocked until a unit head is assigned.
2. **Unit staff** (not the unit head) go to the unit head when the chain includes unit head.
3. After unit-head approval, requests escalate to the HOD when the chain is **both**. With **unit head only**, the action executes after unit-head approval.
4. The **acting HOD** of the owning department, and **Super Admin**, execute immediately on submit.
5. **HOD seniority:** the department head may approve or reject a request that is still waiting on the unit head; that decision is final (unit-head step skipped).
6. If the department has **no HOD** and the chain still needs one, any completed unit-head step executes so rollout is not frozen.

Pending work returns HTTP **202** with status `pending_approval`. The staff portal shows a notice. Reviewers use **Overview → Approvals** (`/approvals`): **Needs my review**, **Submitted by me**, and **Decided**. Approve or reject with an optional comment. Designation is the review gate; Super Admin can decide any open request.

---

## 7. User management

| Task | Steps |
|------|-------|
| Create staff user | Users → Add user; set name, email, password, roles, optional staff title and office placement |
| Disable user | Edit user → set status to disabled (reason required) |
| Reset password (admin) | Edit user → enter new password |
| Assign roles | Edit user or use role assignment with audit reason |

Disabled users cannot sign in. All significant user changes are recorded in the audit trail.

The last Super Admin role cannot be removed from the last Super Admin account.

---

## 8. Staff portal modules

### 8.1 Overview

- **Home** — Dashboard with welcome message, optional summary stats (`reports.view`), and quick access links
- **Students** — Student records (`students.view_any`)
- **Approvals** — Inbox for office unit heads, HODs, and Super Admin. Shown automatically to designated heads. Optional permission `office.approvals.view` does not replace designation.

### 8.2 Applications

The **Applications** section is the active admissions pipeline, split by entry channel:

- **Undergraduate** — UTME, Direct Entry, and Transfer (`admissions.view`)
- **JUPEB** — JUPEB applicants (`admissions.view`)
- **Postgraduate** — PG applicants (`admissions.view`)

Staff advance complete files through:

`submitted` → `screening` → `verification` → `shortlisting` → `recommended` → `approved` → `offer_issued` → matriculation

Transfer files include a **credit assessment** step after verification (`admissions.credit_assess`). Postgraduate files may include extra PG review steps.

Applicants who are **matriculated and have paid at least 25% of current-session tuition** leave Applications lists and appear under **Registrations**.

Staff can open an application file to review biodata, form answers, documents, eligibility, and (where permitted) update fields. Identity fields stay locked when NIN was verified.

### 8.3 Registrations

The **Registrations** section lists students who have:

1. **Completed admission** — application stage is `matriculated` and a student record exists
2. **Met the tuition threshold** — at least **25%** of current-session tuition paid (paid or partial tuition invoices)

Channels mirror Applications (Undergraduate, JUPEB, Postgraduate) and require `registrations.view` plus the matching office portal link.

Course registration, unit limits, and registration extensions are under **Academic → Enrolment**, not under Registrations.

### 8.4 Academic — Admission Setup

| Page | Purpose | Permission |
|------|---------|------------|
| Campuses | Campus catalogue | `academic.campuses.manage` |
| Colleges | Colleges / faculties; bulk spreadsheet import | `academic.colleges.manage` |
| Departments | Academic departments; bulk spreadsheet import | `academic.departments.manage` |
| Sessions & semesters | Academic sessions, terms, and end-of-session promotion | `academic.sessions.manage`, `academic.sessions.close` |
| Graduation | Confirm conferment and start the studentship clock | `academic.graduate` |
| Levels | Study levels (100, 200, …) | `academic.levels.manage` |
| Courses | Course catalogue; bulk spreadsheet import | `academic.courses.manage` |

#### Catalogue bulk import

Use **Template / Choose file / Import** on Colleges, Departments, Programmes, Courses, and O'level. Import **creates** new rows only: matching records are **skipped** (not updated). Single-row Add/Edit still goes through office approval; bulk import writes directly.

Import order: **Colleges → Departments → Programmes → Courses**. O'level is independent. Campuses must already exist (there is no campus import). Download the template and copy parent **ids** from the lookup sheets; do not paste data into those sheets.

| Page | Required columns | Duplicate skip |
|------|------------------|----------------|
| Colleges | `name`, `campus_id` | Same `code`, or same name + campus if code is blank |
| Departments | `name`, `college_id` | Same `code`, or same name + college if code is blank |
| Programmes | `name`, `department_id`, `award_type`, `study_level`, `duration_years`, `entry_modes` | Same `code`, or same name + department if code is blank |
| Courses | `code`, `title`, `department_id` | Same course `code` (no update, no extra programme attach) |
| O'level | `name` | Same `code` if present, otherwise same name |

Unknown parent ids fail that row only. Course `course_type` is `general`, `faculty`, or `departmental`. Programme `entry_modes` is comma-separated (`utme,de`). Optional course columns: `units`, `course_type`, `programme_id`, `level_id`.

#### Close session (level promotion)

At the end of an academic year, close the session to promote students and lock the session record.

1. Open **Academic → Admission Setup → Sessions & semesters**.
2. Confirm programme **duration** values are correct (they define final year: 4-year UG → 400L, 2-year PG → level 2).
3. Click **Close session** on the target session. Review the preview counts (promoted, final-year unchanged, inactive skipped).
4. Confirm **Close session and promote**. All **active** students with a programme move up one level (100→200 for UG, +1 for PG) until the programme final year. Students already at final year stay **active** with no level change (graduation is separate).
5. Optional: enable **Auto-close on end date** when creating or editing a session so the nightly calendar job (`academic:sync-calendar`) closes the session after `ends_on` and runs the same promotion.

Closed sessions cannot be deleted. Re-opening a closed session is not supported.

#### Graduation and studentship expiry

Studentship is not ended by session close. The registrar confirms graduation, then a nightly job ends studentship after the configured number of years (default **2**).

1. After senate conferment, open **Academic → Admission Setup → Graduation** (`academic.graduate`). Assign the `graduation` portal link in Department Setup.
2. Filter final-year **active** students (the same cohort session close left unchanged). Optionally choose a graduating session for the audit record.
3. Select students, set the **conferment date**, and confirm. Status becomes **graduated**. `studentship_expires_at` is conferment date plus the years in **Application settings → Studentship after graduation**.
4. Late senate lists: on **Overview → Students**, use **Confer** on an active student (does not require final year).
5. Until expiry, graduates can still sign in to the student portal. They cannot register courses for a new session.
6. The scheduler runs `students:expire-studentship` daily (after `academic:sync-calendar`). Graduates whose expiry date has passed become **alumni** and student-portal login is blocked. Staff can still open the record. Dual staff accounts keep staff-portal access.

### 8.5 Academic — Application Setup

| Page | Purpose | Permission |
|------|---------|------------|
| Programmes | Programmes, entry modes, eligibility, workflow template; bulk spreadsheet import | `academic.programmes.manage` |
| Application windows | Open/close intakes by entry mode and session, with application fee | `academic.intakes.manage` |
| Candidate data | Upload JAMB candidate lists used at student signup | `admissions.import` |
| Import applicants | Create applicant accounts and applications from another portal | `admissions.import` |
| O'level | O'level subject catalogue; bulk spreadsheet import | `academic.olevel.manage` |

Programme and O'level bulk import uses the same template + skip-duplicates pattern as Admission Setup (§8.4). Import programmes after departments. O'level does not depend on the college/department tree.

#### Candidate data

Upload JAMB candidate spreadsheets **before** new applicants register. Students must match a registration number on this list when policy requires it.

- Formats: `.xlsx`, `.xls`, `.csv`
- Required: registration number (`rg_num`, `registration_number`, and similar headers)
- Optional: candidate name, sex, state, aggregate, course, LGA, UTME subject scores
- Select the academic session on upload

#### Import applicants

Use this when moving people from another admissions portal into this system.

1. Select the **application window** (intake) and **category** (UTME, Direct Entry, JUPEB, Transfer, or Postgraduate). The window's entry mode must match the category.
2. Download the **template** for that category and fill one row per applicant on the **Applicants** sheet. Do not rename columns. Extra sheets (**Campuses**, **Colleges**, **Departments**, **Programmes**, **Levels**, **O-level subjects**) are lookup lists — copy the programme **id** onto the Applicants sheet; do not paste rows into those sheets. The application window is selected on the page, not in the file.
3. Optionally tick **Verify NIN during upload (Prembly is called for every row)**. Failed NINs are skipped and no account is created. Leave this off to store NIN without live verification; the applicant can verify later in the form.
4. Optionally tick **Email portal passwords** (default on). If the spreadsheet `password` column is filled, that plaintext is hashed and stored and is not emailed unless this box is checked. If the column is blank, a new password is generated and emailed when the box is on.
5. Upload `.xlsx`, `.xls`, or `.csv`. The job is queued when NIN verification is on **or** the file has 40 or more data rows (unless the queue driver is `sync`). Wait for the result summary.
6. Download **failed rows** if any rows were skipped.

**Required columns (all categories):** email, phone, nin, first_name, last_name, first_choice_programme_id. UTME and Direct Entry also require `jamb_registration`.

**Programme ids** must already exist for that entry mode (copy from the Programmes lookup sheet **id** column). **Documents are not imported** from Excel; applicants upload remaining files after they sign in.

Complete rows land at stage **`submitted`** (application fee is skipped) so staff can process admission immediately. Incomplete rows stay in the form so the applicant can finish after login.

After import, the applicant signs in on the student portal with **application number or JAMB + password** (not email). Duplicate email, NIN, JAMB, or application number is skipped.

### 8.6 Academic — Enrolment, PG, exam clearance

- **Offerings** — Course offerings per session (`academic.offerings.manage`)
- **Course registration** — Staff view of student enrolments (`academic.enrollments.manage`). Students must have paid at least 25% tuition before self-registering. Staff can still register below that threshold when they provide a reason.
- **Unit limits** — Credit-unit caps (`academic.enrollments.manage`)
- **Registration extensions** — Review late-registration requests (`academic.extensions.review`)
- **Import students** — Create continuing students with a supplied matric number (`students.import`). Import invoices and wallet history first. Login uses matric number. They appear under Registrations only when tuition invoices show at least 25% paid (same rule as other students).
- **PG research** — Postgraduate research records (`pg.view`)
- **Exam clearance** — Exam clearance lists (`exam_clearance.view`)

### 8.6a Academic — Results processing

Result entry follows a controlled workflow before students can see grades on the student portal.

**Statuses:** `draft` → `submitted` → `board_ready` (via faculty approval) → `board_cleared` → `released`. Returns use `correction_required`. Only `draft` and `correction_required` rows are editable. Students see only **released** grades; CGPA uses released rows only. Carry-over detection also ignores unreleased grades.

| Page | Purpose | Permission |
|------|---------|------------|
| Results dashboard | Counts by status | `results.read` |
| Result entry | Search students, enter CA/exam/total | `results.read` + `results.write` / `results.submit` |
| CSV import | Bulk draft import (`matric,score` or ca/exam columns) | `results.import` |
| Approvals | Submit queue, faculty approve/return, printable dept/faculty lists | `results.submit` or `results.faculty_approve` |
| Board | Board clear / request corrections, printable board lists | `results.board` |
| Release | Release board-cleared grades to students | `results.release` |
| Grading scale | Edit letter boundaries (seeded default 5.0 scale) | `scales.manage` |

Grade create/update/import/status changes are written to the platform **Audit** trail (`module = results`), not a separate Results audit screen.

**Typical flow**

1. Assign Results portal links under Department Setup (`results`, `results-students`, `results-approvals`, `results-board`, `results-release`, etc.) and grant the matching permissions.
2. Review **Grading scale** before first use.
3. Enter or import drafts on Result entry / CSV import.
4. Submit → faculty approve (or return) on Approvals. Download printable lists as needed.
5. Board clear on Board (may require office approval).
6. Release on Release (may require office approval). Students then see letter grades and CGPA.

E-exam sync is not included in this release.

### 8.7 Services

- **Fees & payments** — Fee catalogue, sundry fees, rebates, programme fees, invoice generation, invoices, student financial status, **Import invoices**, and **Import wallet history** (`finance.invoices.manage`; dedicated import permission `finance.invoices.import` is also accepted)

#### Import invoices and wallet history

Use these when moving continuing students from another portal. **Do not** invent a “legacy registered” flag or auto-credit wallets from invoice `paid_amount`.

1. **Fees & payments → Import invoices.** Spreadsheet keyed by `matric_number`. One row is one invoice. Extra rows with the same `invoice_number` add extra payments. Tuition requires `installment_percent` (25/50/75/100). `paid_amount` records money received on the invoice. If the student does not exist yet, rows stay **pending** until Import students runs.
2. **Fees & payments → Import wallet history.** One row is one credit or debit. Replay is in `occurred_at` order. Wallet credit does **not** count as tuition paid. If a debit would take the wallet below zero, that row and remaining rows for the same matric are skipped.
3. **Academic → Enrolment → Import students.** Select an application window and category. Download the template and copy `programme_id` from the **Programmes** lookup **id** column and `current_level` from **Levels**. Required: email, phone, nin, first_name, last_name, programme_id, matric_number, current_level. Creates a user (student role), a historical application at stage `matriculated`, and a student record with the **supplied** matric (not an auto-generated `BUT/{year}/M/{####}`). Then posts pending invoices and wallet rows for that matric.

If an old payment was wallet-funded, record `paid_amount` on the invoice sheet **and** a matching **debit** on the wallet sheet. These imports do not call Paystack and do not settle invoices from the wallet automatically.
- **Clinic** — Queue, student charts, encounters, prescriptions, sick notes, NHIS-aware billing (`medical.view_any` / `medical.manage` / `medical.billing`)
- **Hostel** — Hostels, blocks, rooms, level windows, queue, and allocations (`hostel.view`; manage rooms with `hostel.manage`; allocate with `hostel.allocate`)

#### Hostel room bulk import

On **Services → Hostel → Rooms**, download the **template**, fill the **Rooms** sheet, and import. Hostels and blocks must already exist. Import **creates** rooms only: the same room number in the same block is **skipped**. Beds are created from `capacity`. Copy `hostel_id` and `block_id` from the lookup **id** columns (the block must belong to that hostel). Required columns: `hostel_id`, `block_id`, `number`, `capacity`. Optional: `gender` (`male`/`female`), `is_active`. Single-row Add still goes through office approval; bulk import writes directly.

- **Documents** — Issue documents (`documents.issue`)

### 8.8 Administration

- **Users** — Staff account management (`users.manage`)
- **Roles / Permissions** — Access control (`roles.manage`)
- **Department Setup** — Office hierarchy and portal links (`institution.manage`)

### 8.9 System

- **Audit** — Activity log (`audit.view`)
- **Reports** — Summary and custom reports (`reports.view`; building or sharing saved reports also needs `reports.manage`)
- **Announcements** — Campus communications
- **Integrations** — External endpoints including Prembly / NIN (`integrations.view`)
- **Application settings** — Global security policies (`settings.manage`)
- **Resources** — Download operational documents such as this SOP (`resources.view`)
- **API documentation** — Self-hosted OpenAPI UI at `/api/docs`

---

## 9. Audit trail

Actions such as sign-in, user changes, role updates, profile edits, candidate uploads, and applicant imports are logged.

- **View logs:** System → Audit (`audit.view`)
- Logs include actor, action, entity, timestamp, and optional reason (for example user disable)

Use the audit trail for compliance reviews and incident investigation.

---

## 10. Standard operating workflows

### 10.1 Onboard a new admissions officer

1. Create the user in **Users** with an Admissions role (include `admissions.view`, `registrations.view`, and `admissions.import` if they will upload lists).
2. In **Department Setup**, ensure the admissions office has Applications links (`admissions-undergraduate`, `admissions-jupeb`, `admissions-postgraduate`) and, if needed, Registrations (`registrations-*`), Candidate data (`candidate-data`), and Import applicants (`import-applicants`).
3. Assign the user to the correct office department, unit, or sub-unit.
4. Confirm the user can sign in and sees the expected sidebar items.
5. If 2FA is enabled, ensure they complete authenticator setup on first login.

### 10.2 Prepare a new admissions cycle

1. Confirm **Sessions & semesters** for the cycle.
2. Confirm **Programmes** have the correct entry modes and are active.
3. Open **Application windows** for each category (UTME, DE, JUPEB, Transfer, PG) with the application fee set.
4. Upload **Candidate data** for UTME/DE sessions before applicants register (if JAMB-list enforcement is required).
5. If migrating from another portal, use **Import applicants** (see §8.5).

### 10.3 Import applicants from another portal

1. Open **Academic → Application Setup → Import applicants**.
2. Choose category and matching application window.
3. Download the template; fill from the old-portal export on the Applicants sheet. Copy programme **ids** from the Programmes lookup sheet. Leave `password` blank unless the old portal provided a reusable plaintext password (never paste a password hash).
4. Leave **Verify NIN** off unless Prembly should run during the upload.
5. Upload the file and review created / skipped / emailed counts.
6. Download failed rows, correct them, and re-upload only those rows.
7. Tell applicants to sign in with application number or JAMB (not email) and the password they received (or the old plaintext password if it was supplied).

### 10.4 Configure registrations access for student records desk

1. Grant the role `registrations.view`.
2. In **Department Setup → Links**, tick the required Registrations channel links for that office.
3. Verify staff see matriculated students with at least 25% tuition under **Registrations**, not under **Applications**.

### 10.5 Restrict a unit to specific functions

1. Identify the office unit in **Department Setup**.
2. Open **Links** and select only the required modules.
3. Assign staff only to that unit (not a broader parent node unless intended).
4. Verify each staff member's role includes permissions for the assigned links.

### 10.6 Enable organisation-wide 2FA

1. Sign in as a user with `settings.manage`.
2. Open **Application settings**.
3. Enable **Require 2FA for staff**.
4. Save.
5. Notify all staff to install an authenticator app before their next sign-in.

### 10.7 Investigate “staff sees no links”

1. Confirm the user is not Super Admin (office scoping applies).
2. Check **Users** → office placement is set and not stale.
3. Check **Department Setup → Links** on the assigned node include expected items (including `candidate-data` or `import-applicants` if those pages are missing).
4. Confirm the user's role grants the permission for each link (for example `admissions.view` for Applications, `admissions.import` for Candidate data / Import applicants, `registrations.view` for Registrations).

### 10.8 Assign office heads and use Approvals

1. Place the intended head in the office on **Users** (department tree for an HOD; that unit or one of its subunits for a unit head).
2. On **Department Setup**, edit the department or unit and select **Head of department** or **Unit head**.
3. Confirm they see **Approvals** in Overview after refresh.
4. When a staff member in a linked module is told the action is waiting for approval, the unit head and/or HOD opens **Approvals → Needs my review**, reads the summary, and approves or rejects.
5. After final HOD approval the original action runs. Rejection leaves no change.

---

## 11. Troubleshooting

| Issue | Likely cause | Resolution |
|-------|--------------|------------|
| “Student portal required” on staff URL | Account has applicant/student role only | Use student portal or assign a staff role and staff record |
| Only Home visible | No office links configured or stale placement | Assign office and configure links |
| Candidate data or Import applicants missing | Office link or `admissions.import` missing | Tick `candidate-data` / `import-applicants` and grant `admissions.import` |
| Import students missing | Office link or `students.import` missing | Tick `import-students` and grant `students.import` |
| Import invoices / wallet missing | Office link or finance permission missing | Tick `import-invoices` / `import-wallet` and grant `finance.invoices.manage` (or `finance.invoices.import`) |
| Imported student cannot sign in | Used email or JAMB after matriculation | Sign in with the supplied **matric number** |
| Imported student not on Registrations | Tuition invoices below 25% paid | Import invoices with tuition `paid_amount` / `installment_percent`; wallet credit alone does not qualify |
| Applicant cannot sign in with email | Student portal does not accept email as login | Use application number, JAMB registration, or matric number |
| Import skipped a row | Duplicate email/NIN/JAMB, missing required field, unknown programme id, or NIN verify failed | Download failed rows; fix and re-upload |
| NIN import created no user | Verify NIN was on and Prembly rejected the NIN | Correct NIN or import with verification off |
| Action stays pending after approve | Reviewer is not the designated head, or unit-head step still required | Confirm HOD/unit-head assignment; Super Admin can decide any open request |
| Subunit staff cannot submit | Parent unit has subunits but no unit head | Assign a unit head on Department Setup |
| “Already waiting for office approval” | Duplicate open request for the same action/subject | Open Approvals and decide or wait for the existing request |
| 2FA code rejected | Clock drift or wrong secret | Re-sync device time; restart 2FA setup if needed |
| Signed out unexpectedly | Inactivity policy | Sign in again; adjust policy in Application settings if appropriate |
| Password change blocked | Rotation policy expired or current password omitted | Enter current password and a new password that meets the rules |
| Cannot access Application settings | Missing `settings.manage` | Super Admin or ICT assigns permission |
| Cannot change password on Profile | Current password not entered | Enter current password with the new password |

---

## 12. Document control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | Aug 2026 | Platform team | Initial release covering staff portal, office nav, and security settings |
| 1.1 | Aug 2026 | Platform team | Applications/Registrations split, academic setup permissions, registrations API |
| 1.2 | Aug 2026 | Platform team | Align with live sidebar (Admission / Application / Enrolment setup, Department Setup, finance submenu), candidate data and applicant import, NIN/password import rules, 25% tuition registrations rule, profile current-password requirement |
| 1.3 | Aug 2026 | Platform team | Office HOD/unit-head assignment, Approvals inbox, 202 pending-approval behaviour, gated module mutations |
| 1.4 | Aug 2026 | Platform team | Continuing-student import (supplied matric), invoice and wallet history import keyed by matric, registrations still require ≥25% tuition paid |
| 1.5 | Aug 2026 | Platform team | Catalogue bulk spreadsheet import for colleges, departments, programmes, O'level, and courses; matching rows skipped; import order Colleges → Departments → Programmes → Courses |
| 1.6 | Aug 2026 | Platform team | Hostel room bulk spreadsheet import (template + skip-duplicates; hostels and blocks must already exist) |
| 1.7 | Aug 2026 | Platform team | Bulk uploads key parents by lookup id (campus_id, college_id, department_id, programme_id, level_id, hostel_id, block_id) |

**Distribution:** Available for download in the staff portal under **System → Resources** by users with the `resources.view` permission.

**Review cycle:** Review quarterly or after major platform releases.

---

## 13. Contact

For access issues, office placement, or security policy changes, contact **ICT Services** or your designated **Super Admin**.

---

*End of document*
