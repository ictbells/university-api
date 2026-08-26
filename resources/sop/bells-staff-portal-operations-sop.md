# Bells University Staff Portal — Standard Operating Procedure

**Document ID:** SOP-STAFF-PORTAL-001  
**Version:** 1.25  
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
- Academic catalogue, admission and application sessions, candidate lists, applicant import, and continuing-student import
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
| `academic.sessions.manage` | Academic Sessions |
| `academic.levels.manage` | Levels |
| `academic.courses.manage` | Courses |
| `academic.programmes.manage` | Programmes |
| `academic.intakes.manage` | Application sessions |
| `academic.olevel.manage` | O'level |
| `academic.offerings.manage` | Offerings |
| `academic.enrollments.manage` | Course registration and unit limits |
| `academic.extensions.review` | Registration extensions |
| `exam_clearance.view` | Exam clearance |

Legacy permissions (`institution.manage`, `academic.catalog.manage`) still grant access to matching setup areas for backward compatibility.

`institution.manage` is required for **Department Setup** (office hierarchy and portal links). Institution is not an assignable portal link and is not shown on the staff sidebar.

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
| **Academic** | Admission Setup (dropdown), Application Setup (dropdown), Courses (dropdown), Exam clearance |
| **Services** | Fees & payments (dropdown), Clinic, Hostel, Documents |
| **Administration** | Users, Roles, Permissions, Department Setup |
| **System** | Audit, Reports, Announcements, Integrations, Application settings, Resources |

**Admission Setup** contains: Campuses, Colleges, Departments, Academic Sessions, Levels, Graduation, Import students.

**Application Setup** contains: Application sessions, Programmes, O'level, Candidate data, Import applicants.

**Courses** contains: Course catalog, Programme courses, Offerings, Course registration, Unit limits, Registration extensions.

**Results** contains: Results dashboard, Result entry, CSV import, Department uploads, Faculty Approval, Board, Release, Grading scale. Grade changes appear under System → Audit (module `results`).

**Fees & payments** contains: Fee categories, Fee items, Rebates, Programme fees, Generate invoice, Invoices, Students Financial Status, Import invoices, Import wallet history.

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

**Inheritance:** Units inherit portal links assigned on their department. Subunits inherit links from both the parent unit and the department. You only need to assign a module once at the department (or unit) level; child offices still only see modules their role permissions allow. Extra links can still be assigned directly on a unit or subunit when needed.

Staff placed at a node see assigned links **plus inherited parent links** (and department placement also includes links assigned on child units/subunits), subject to their role permissions. Home is always available when no other links resolve.

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

When assigning a portal link on **Department Setup → Links**, every assigned link can be given **without** office approval. Use the gear on that link to configure approval when needed:

1. **Require office approval** — off means staff with permission act immediately; on lets you pick Create / Update / Delete gates.
2. **Create / Update / Delete** — which mutation types require office approval (Update also covers workflow actions such as allocate, publish, board clear).
3. **Who must approve** — unit head only, department head only, or **both** (normal path: unit head → department head).

New assigned links default to **no approval**. Existing links keep the Create/Update/Delete flags already saved. When a flag is on, staff mutations on that module wait for office approval (HTTP 202 `pending_approval`). **Super Admin and the owning HOD still apply immediately** and do not need to submit their own work for review.

Saving **portal links** (including the approval gear) applies immediately so those flags take effect. Creating or editing office departments, units, and subunits still waits when Office setup Create/Update/Delete is on.

Assigning programme fees (the Assign fees POST) and copying a schedule wait when **Create or Update** is on for **Fees & payments**.

When a mutation requires approval for an owned module:

1. **Subunit staff** usually go to the parent **unit head** first (unless the chain is department-head only). If the unit has subunits and no unit head, the action is blocked until a unit head is assigned.
2. **Unit staff** (not the unit head) go to the unit head when the chain includes unit head.
3. After unit-head approval, requests escalate to the HOD when the chain is **both**. With **unit head only**, the action executes after unit-head approval.
4. The **acting HOD** of the owning department, and **Super Admin**, execute immediately on submit.
5. **HOD seniority:** the department head may approve or reject a request that is still waiting on the unit head; that decision is final (unit-head step skipped).
6. If the department has **no HOD**, other staff still wait. Super Admin can decide the request in Approvals. After a unit head has already approved, a missing HOD no longer blocks execution of that reviewed request.

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
- **Students** — Student records (`students.view_any`). Filter by admission session and study level.
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

Staff can open an application file to review biodata, form answers, documents, eligibility, and (where permitted) update fields. Identity fields stay locked when NIN was verified. NIN lookup fills **phone** and **address** on the user and the application form when Prembly returns them (fields stay editable if those values are missing). JAMB **programme** fields are chosen from the university programme catalogue, not typed freely. Each O’level sitting is limited to **nine** subjects.

From the application **Decision** panel, staff can **revert the last decision** (advance or rejection). That returns the file to the previous stage. Matriculated files and files whose acceptance fee has already been paid cannot be reverted. Office-head approval still applies where the office requires it.

### 8.3 Registrations

The **Registrations** section lists students who have:

1. **Completed admission** — application stage is `matriculated` and a student record exists
2. **Met the tuition threshold** — at least **25%** of current-session tuition paid (paid or partial tuition invoices)

Channels mirror Applications (Undergraduate, JUPEB, Postgraduate) and require `registrations.view` plus the matching office portal link. Lists filter by study **level** (session is already on the page).

Course registration, unit limits, and registration extensions are under **Academic → Courses**, not under Registrations.

### 8.4 Academic — Admission Setup

| Page | Purpose | Permission |
|------|---------|------------|
| Campuses | Campus catalogue | `academic.campuses.manage` |
| Colleges | Colleges / faculties; bulk spreadsheet import | `academic.colleges.manage` |
| Departments | Academic departments; bulk spreadsheet import | `academic.departments.manage` |
| Academic Sessions | Academic sessions, terms, and end-of-session promotion | `academic.sessions.manage`, `academic.sessions.close` |
| Levels | Study levels (100, 200, …) | `academic.levels.manage` |
| Graduation | Confirm conferment and start the studentship clock | `academic.graduate` |
| Import students | Create continuing students with a supplied matric number (`students.import`). Import invoices and wallet history first. Invoice rows also match old application number or JAMB. Login uses matric number. They appear under Registrations only when tuition invoices show at least 25% paid | `students.import` |

#### Catalogue bulk import

Use **Template / Choose file / Import** on Colleges, Departments, Programmes, Courses, and O'level. Import **creates** new rows only: matching records are **skipped** (not updated). Single-row Add/Edit and bulk import both go through office approval when Create is required on that link.

Import order: **Colleges → Departments → Programmes → Courses**. O'level is independent. Campuses must already exist (there is no campus import). Download the template and copy parent **ids** from the lookup sheets; do not paste data into those sheets.

| Page | Required columns | Duplicate skip |
|------|------------------|----------------|
| Colleges | `name`, `campus_id` | Same `code`, or same name + campus if code is blank |
| Departments | `name`, `college_id` | Same `code`, or same name + college if code is blank |
| Programmes | `name`, `department_id`, `award_type`, `study_level`, `duration_years`, `entry_modes` | Same `code`, or same name + department if code is blank |
| Courses | `code`, `title`, `department_id` | Same course `code` (no update, no extra programme attach) |
| O'level | `name` | Same `code` if present, otherwise same name |

Unknown parent ids fail that row only. Course `course_type` is `general`, `faculty`, or `departmental`. Course `status` is `core`, `elective`, or `required`. Programme `entry_modes` is comma-separated (`utme,de`). Optional course columns: `units`, `course_type`, `status`, `programme_id`, `level_id`.

#### Close session (level promotion)

At the end of an academic year, close the session to promote students and lock the session record.

1. Open **Academic → Admission Setup → Academic Sessions**.
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
| Application sessions | Open/close intakes by entry mode and admission session. Application and acceptance amounts are set in the fee catalog | `academic.intakes.manage` |
| Programmes | Programmes, entry modes, eligibility, workflow template; bulk spreadsheet import | `academic.programmes.manage` |
| O'level | O'level subject catalogue; bulk spreadsheet import | `academic.olevel.manage` |
| Candidate data | Upload JAMB candidate lists used at student signup | `admissions.import` |
| Import applicants | Create applicant accounts and applications from another portal | `admissions.import` |

Programme and O'level bulk import uses the same template + skip-duplicates pattern as Admission Setup (§8.4). Import programmes after departments. O'level does not depend on the college/department tree.

#### Programme workflow template

When creating or editing a programme, **Workflow** chooses the **admissions and enrolment stage path** used after an applicant submits. It does **not** define the student application form steps (NIN, JAMB, O'Level, and so on). Those follow the programme’s **admission categories** (UTME, DE, JUPEB, Transfer, PG).

Leave the field blank unless the programme needs a different path. If none is selected when creating or saving, the system creates the catalog (if missing) and assigns:

| Condition | Template assigned |
|-----------|-------------------|
| Study level / entry mode is postgraduate **and** Research degree is on | Research postgraduate |
| Study level / entry mode is postgraduate (not research) | Taught postgraduate |
| Otherwise | Undergraduate / JUPEB (`screening` → `verification` → `shortlisting` → `recommended` → `approved` → `offer_issued`) |

| Template | Typical programmes | Stage path (summary) |
|----------|--------------------|----------------------|
| **Undergraduate / JUPEB** | UTME, DE, JUPEB | Application → Screening → Verification → Shortlisting → Recommendation → Approval → Offer → Registration |
| **Undergraduate transfer** | Transfer | Same as UG, with **Credit assessment** before shortlisting |
| **Taught postgraduate** | Coursework PG | Shorter PG path: Screening → Recommendation → Approval → Offer → Registration |
| **Research postgraduate** | MPhil / PhD-style | PG path plus proposal, supervisor, and panel; later research stages (progress, thesis, viva, corrections, final approval, graduation) |

**Notes**

- Transfer applicants can still follow the transfer workflow by **entry mode** even if the programme’s stored template is Undergraduate / JUPEB.
- Pair **Research postgraduate** with **Research degree** when the applicant form must collect research proposal and supervisor preferences.
- Bulk programme import assigns the default template from study level / entry modes / research flag; it does not accept a workflow column in the spreadsheet.

#### Admission session vs application session

These are two calendars, not one record with a type flag.

- **Admission session** — Academic → Admission Setup → Academic Sessions. Used by enrolled students (course registration, hostels, session close and promotion). A session becomes the live calendar only when one of its semesters is marked **Current**.
- **Application session** — Academic → Application Setup → Application sessions (intake). Open when **Accepting applications** is on and today is within the open and close dates. Application and acceptance fees are **not** edited here; they live in **Fees & payments → Fee items** as `application_fee` and `acceptance_fee` lines **per entry mode** (UTME, DE, JUPEB, Transfer, PG). Invoices use the catalog amount and fall back to a stored session amount only if no catalog line exists.

**Lifecycle for a new year**

1. Create the **admission session** (and semesters) **without** marking any semester current — the previous year stays current for enrolled students.
2. Open **application sessions** on that admission session.
3. **Stop accepting** when the application window ends.
4. **Run admission** (review, offers, acceptance, matriculation).
5. **Set a semester current** so this admission session becomes active for students.

The platform blocks step 5 while any application session on that admission session is still accepting.

New applicant **signup** on the student portal requires choosing a specific **application session** first. An open UTME session does not let a postgraduate or transfer applicant create an account. NIN preview and account creation are rejected unless that session is accepting. Successful NIN preview returns names plus **phone** and **address** when the identity provider supplies them, so the register step and later application form can be prefilled. UTME and Direct Entry also require a JAMB number; if a candidate list has been uploaded for that session, the number must appear on it. Signup then starts the application for the chosen session. If no session is accepting, the API returns *Applications are not open. There is no active application session, so you cannot create an account.* Staff applicant import and existing applicant login are not blocked.

#### Candidate data

Upload JAMB candidate spreadsheets **before** new applicants register. Students must match a registration number on this list when policy requires it.

- Formats: `.xlsx`, `.xls`, `.csv`. Download the **template** from the page and keep the header row.
- Required: registration number (`registration_number` / `rg_num`)
- Optional: candidate name, sex, state, aggregate, course, LGA, UTME subject scores
- Select the **application session** on upload (the list is attached to that session’s admission year)

#### Import applicants

Use this when moving people from another admissions portal into this system.

1. Select the **application session** (intake) and **category** (UTME, Direct Entry, JUPEB, Transfer, or Postgraduate). The session's entry mode must match the category.
2. Download the **template** for that category and fill one row per applicant on the **Applicants** sheet. Do not rename columns. Extra sheets (**Campuses**, **Colleges**, **Departments**, **Programmes**, **Levels**, **States**, **LGAs**, **O-level subjects**) are lookup lists. Copy **ids** (not names from the old portal) for programme, state, LGA, and O-level subjects; import stores this system’s official titles. Do not enter college or department on the Applicants sheet. **Country** is Nigeria or Non-Nigeria. Maximum **two** O-level sittings (`sitting1_` / `sitting2_`; sitting 2 optional). UTME/JUPEB has **two** JAMB institution slots (`utme_institution_1` / `utme_programme_1` and `_2`) — enter institution and programme **names**, not ids. The application session is selected on the page, not in the file.
3. Optionally tick **Verify NIN during upload (Prembly is called for every row)**. Failed NINs are skipped and no account is created. Leave this off to store NIN without live verification; the applicant can verify later in the form.
4. Optionally tick **Email portal passwords** (default on). If the spreadsheet `password` column is filled, that plaintext is hashed and stored and is not emailed unless this box is checked. If the column is blank, a new password is generated and emailed when the box is on.
5. Upload `.xlsx`, `.xls`, or `.csv`. The job is queued when NIN verification is on **or** the file has 40 or more data rows (unless the queue driver is `sync`). Wait for the result summary.
6. Download **failed rows** if any rows were skipped.

**Required columns (all categories):** email, phone, nin, first_name, last_name, first_choice_programme_id. UTME and Direct Entry also require `jamb_registration`.

**Programme ids** must already exist for that entry mode (copy from the Programmes lookup sheet **id** column). **Documents are not imported** from Excel. Import **does not submit** the application: rows stay at **`form_in_progress`** so the applicant must re-upload required documents and submit after they sign in. Incomplete rows also stay in the form so they can finish missing fields. Import invoices first when application fee was already paid (keyed by `application_number` or `jamb_registration`); this step posts matching rows, links them to the application, and records the fee as paid. Otherwise an **unpaid** application fee is generated from the fee catalog for that session’s entry mode (falling back to the session amount if no catalog line exists).

After import, the applicant signs in on the student portal with **application number or JAMB + password** (not email). Duplicate email, NIN, JAMB, or application number is skipped.

### 8.6 Academic — Courses and exam clearance

- **Course catalog** — Course catalogue with Core / Elective / Required status; bulk spreadsheet import (`academic.courses.manage`).
- **Programme courses** — Assign catalog courses to a programme by college, department, and admission category (UTME, Direct Entry, JUPEB, Transfer, Postgraduate). A programme that accepts several categories appears under each of them. Students on that programme can register only from current-term offerings of the mapped courses (`academic.programmes.manage`).
- **Offerings** — Course offerings per session (`academic.offerings.manage`). Filter by admission session and study level.
- **Course registration** — Staff view of student enrolments (`academic.enrollments.manage`). Students start add/drop on the student portal **Course registration** page. They must have paid at least 25% tuition before Register/Drop succeed; the catalogue stays visible while they are blocked. Staff can still register below that threshold when they provide a reason. Unit usage is shown on **one row** (General | Faculty | Departmental | Overall). Search the student picker by session and level.
- **Unit limits** — Credit-unit caps (`academic.enrollments.manage`). Filter by session and level.
- **Registration extensions** — Review late-registration requests (`academic.extensions.review`). Filter by session and level.
- **Exam clearance** — Exam clearance lists (`exam_clearance.view`)

Import students lives under **Admission Setup** (§8.4).

### 8.6a Academic — Results processing

Result entry follows a controlled workflow before students can see grades on the student portal.

**Statuses:** `draft` → `submitted` → `board_ready` (via faculty approval) → `board_cleared` → `released`. Returns use `correction_required`. Only `draft` and `correction_required` rows are editable. Students see only **released** grades; CGPA uses released rows only. Carry-over detection also ignores unreleased grades.

| Page | Purpose | Permission |
|------|---------|------------|
| Results dashboard | Counts by status | `results.read` |
| Result entry | Search students, enter CA/exam/total | `results.read` + `results.write` / `results.submit` |
| CSV import | Bulk draft import (`matric,score` or ca/exam columns) | `results.import` |
| Department uploads | Department grade list, submit drafts, print department list | `results.submit` |
| Faculty Approval | Faculty approve/return submitted grades; print faculty list | `results.faculty_approve` |
| Board | Board-ready/cleared records list; board clear / request corrections; printable board list | `results.board` |
| Release | Release board-cleared grades to students | `results.release` |
| Grading scale | Edit letter boundaries (seeded default 5.0 scale) | `scales.manage` |

Grade create/update/import/status changes are written to the platform **Audit** trail (`module = results`), not a separate Results audit screen. Result entry, approvals, board, and release lists accept **session** and **level** filters (term pickers stay where the list is term-based).

**Typical flow**

1. Assign Results portal links under Department Setup (`results`, `results-students`, `results-department`, `results-approvals`, `results-board`, `results-release`, etc.) and grant the matching permissions.
2. Review **Grading scale** before first use.
3. Enter or import drafts on Result entry / CSV import.
4. On **Department uploads**, review drafts and submit; print the department list as needed.
5. On **Faculty Approval**, approve (or return). Print the faculty list as needed.
6. On **Board**, review the records list, then board clear (may require office approval).
7. Release on Release (may require office approval). Students then see letter grades and CGPA.

On the **student portal → Academic**, students can view and print an **unofficial** transcript (released grades only). It is marked unofficial, has **no signature lines**, and cannot be signed from the portal.

### 8.6b Official transcript requests (Registry)

Public requests (for the school website) use the student portal paths **`/transcript-request/undergraduate`**, **`/transcript-request/jupeb`**, and **`/transcript-request/postgraduate`** (no login). **`/transcript-request`** is a chooser for those three. Requesters enter **matric number + account email**, select the **programme** linked to their record for that channel, then select a **transcript type**:

- **E-copy** — email address to send the signed PDF to
- **Within Nigeria** — postal address in Nigeria
- **Outside Nigeria** — postal address outside Nigeria
- **Student copy** — collect at the Registry **or** give a postal address

The fee is quoted only after both programme and type are selected. Finance owns the amount: one catalog line per **programme + transcript type**. Payment is online, then Registry processes the matching channel queue.

**Setup**

1. **Fees & payments → Fee items** — create an active **Official transcript** (`transcript`) line for each programme and transcript type you offer. Amount is owned by Finance. Add the **Official transcript** type first under **Fee categories** if it is missing.
2. **Application settings** — enable **Accept public transcript requests** and at least one delivery mode (collect at Registry, system PDF, staff-uploaded PDF). Optionally edit collection instructions.
3. **Department Setup** — assign portal links **`transcript-undergraduate`**, **`transcript-jupeb`**, and **`transcript-postgraduate`**, and grant `transcripts.view` / `transcripts.process` (Registrar role includes these when re-seeded).

**File storage:** uploads (application documents, NIN photos, transcript PDFs, import spreadsheets) use `FILESYSTEM_DISK`. Set `FILESYSTEM_DISK=s3` plus `AWS_*` credentials to store them on S3.

**Flow**

1. Requester opens the matching public form, submits, and pays online.
2. Staff open **Services → Transcript Requests** (Undergraduate, JUPEB, or Postgraduate), start processing, then **Mark ready** with an enabled delivery mode (upload PDF when required).
3. Requester is emailed; PDF modes include a download link on the public request page.

Unofficial Academic transcript viewing is separate and remains free.

E-exam sync is not included in this release.

### 8.7 Services

- **Fees & payments** — **Fee categories** (charge types, including programme-schedule vs operational) and **Fee items** (priced lines: **application and acceptance fees per entry mode**, **official transcript fees per programme and transcript type**, **Clinic services** visit charges, and **school-fee installment shares**: any programme-schedule catalog line — tuition, ICT, laboratory, infrastructure, and the rest — can be tagged 1st/2nd/3rd/4th 25% or optional Full 100% pay-at-once; application, acceptance, and transcript fees stay online-only and cannot use shares), rebates, **Programme fees** (assign catalog lines to programmes with a naira override per line; copy a schedule to other programmes in the same college), invoice generation, invoices, student financial status, **Import invoices**, and **Import wallet history** (`finance.invoices.manage`; dedicated import permission `finance.invoices.import` is also accepted). Invoices and Generate invoice filter by session and level; Programme fees filter by level. When schedule lines are tagged with installment shares, student school-fee invoices bill those fixed amounts (already-paid fee items are skipped; 50% bills unpaid 1st + 2nd slices). Untagged schedules still pro-rate the full total by 25/50/75/100. The **Medical levy** is a programme schedule charge. **Clinic services** is an operational catalog: Finance sets name and amount; clinic staff attach those lines to a visit (quantity only) and cannot type prices. Hostel **Accommodation** on a school-fee sheet is a schedule catalog item, not a hostel-bed charge.

#### Programme fees (25% matrix)

The bursary sheet is four stacks of named lines with fixed naira amounts, grouped by college/department. Do **not** put the same catalog line on 25% and again on 50%. Create separate catalog items when the same name appears in more than one block (for example **Tuition · 1st 25%** and **Tuition · 2nd 25%**), because the amounts differ.

1. **Fee categories** — mark school charges as programme-schedule (tuition, infrastructure, accommodation, BUSA, ICT, laboratory, and any other sheet heading). Reuse an existing category when the name already matches.
2. **Fee items** — one catalog line per sheet row, with the matching installment share. Default amount can be the most common cell or 0.
3. **Programme fees** — for each spreadsheet column, pick one programme in that group, assign every non-dash cell with its naira override, then **Copy schedule** to the other programmes in the same college (use Select all in this department when the group is one department). Dashes stay unassigned. Blank or 0 amounts are skipped on invoices.
4. Skip **Full 100% (pay at once)** unless bursary wants a discounted lump sum. The sheet grand total is 1st + 2nd + 3rd + 4th 25%. Students who choose 50% or 75% still receive the next unpaid slices, not a pro-rata of that grand total.

#### Import invoices and wallet history

Use these when moving continuing students from another portal. **Do not** invent a “legacy registered” flag or auto-credit wallets from invoice `paid_amount`.

1. **Fees & payments → Import invoices.** Spreadsheet keyed by `matric_number`, `application_number`, or `jamb_registration` (at least one). Application fee is often paid with APP or JAMB before a matric exists. One row is one invoice. Extra rows with the same `invoice_number` add extra payments. Category may be `application_fee`, `tuition`, `acceptance_fee`, and the rest of the catalogue. Tuition requires `installment_percent` (25/50/75/100). `paid_amount` records money received on the invoice. If no matching student or applicant exists yet, rows stay **pending** until Import students or Import applicants runs. On **Import applicants**, matching pending `application_fee` rows are posted and linked; if none match, an unpaid fee is generated from the fee catalog for that session’s entry mode (falling back to the session amount if no catalog line exists).
2. **Fees & payments → Import wallet history.** One row is one credit or debit. Replay is in `occurred_at` order. Wallet credit does **not** count as tuition paid. If a debit would take the wallet below zero, that row and remaining rows for the same matric are skipped.
3. **Academic → Admission Setup → Import students.** Select an application session and category. Download the template and copy `programme_id` from the **Programmes** lookup **id** column and `current_level` from **Levels**. Required: email, phone, nin, first_name, last_name, programme_id, matric_number, current_level. Fill `old_application_number` and `jamb_registration` when those ids were used to pay fees. Creates a user (student role), a historical application at stage `matriculated`, and a student record with the **supplied** matric (not an auto-generated `BUT/{year}/M/{####}`). Then posts pending invoices (matched by matric, application number, or JAMB) and wallet rows. Paid `application_fee` / `acceptance_fee` rows are linked on the application so student finance shows the correct payment status.

If an old payment was wallet-funded, record `paid_amount` on the invoice sheet **and** a matching **debit** on the wallet sheet. These imports do not call Paystack and do not settle invoices from the wallet automatically.
- **Clinic** — Queue, student charts, encounters, prescriptions, sick notes, NHIS-aware billing (`medical.view_any` / `medical.manage` / `medical.billing`). Enrol a student on NHIS by **matric number**. Coverage may be a **percent** of eligible lines (campus default if blank) or a **fixed naira amount**. Visit prices live in **Fees & payments → Fee items** under **Clinic services**. Clinic staff pick those lines and quantity; finalizing invoices the student for the NHIS-adjusted payable (wallet only; not Paystack).
- **Hostel** — Hostels, blocks, rooms, level windows, queue, and allocations (`hostel.view`; manage rooms with `hostel.manage`; allocate with `hostel.allocate`). Open allocation **by category and level**; the switch saves immediately. Undergraduate, JUPEB, and postgraduate are separate — opening Undergraduate 100 Level does not open JUPEB 100 Level. Students see selection **Open** when their category/level window is on for the current semester, and they must have paid at least **25% of current-session tuition** before they can request a bed. A hostel record marked Active does not by itself open student selection.

#### Hostel room bulk import

On **Services → Hostel → Rooms**, download the **template**, fill the **Rooms** sheet, and import. Hostels and blocks must already exist. Import **creates** rooms only: the same room number in the same block is **skipped**. Beds are created from `capacity`. Copy `hostel_id` and `block_id` from the lookup **id** columns (the block must belong to that hostel). Required columns: `hostel_id`, `block_id`, `number`, `capacity`. Optional: `gender` (`male`/`female`), `is_active`. Single-row Add and bulk import both go through office approval when Create is required.

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

1. Create the **Admission session** for the new year with at least two semesters. Do **not** mark any semester current yet — keep the previous year current for enrolled students.
2. Confirm **Programmes** have the correct entry modes and are active.
3. Open **Application sessions** for each category (UTME, DE, JUPEB, Transfer, PG). Set the matching **application fee** and **acceptance fee** under **Fees & payments → Fee items** (one line per entry mode for each). Applicants must select the session they qualify for at signup; opening only UTME does not admit other categories.
4. Upload **Candidate data** for UTME/DE sessions before applicants register (if JAMB-list enforcement is required).
5. If migrating from another portal, use **Import applicants** (see §8.5).
6. When the window ends, **stop accepting** on those application sessions.
7. **Run admission** (pipeline decisions, offers, acceptance fees, matriculation).
8. **Set a semester current** on the new admission session so it becomes the live session for students. The system rejects this while any application session on that admission session is still accepting.

### 10.3 Import applicants from another portal

1. Open **Academic → Application Setup → Import applicants**.
2. Choose category and matching application session.
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
| 1.8 | Aug 2026 | Platform team | Distinguish admission sessions (enrolled students) from application sessions (intakes); student-portal signup requires an accepting application session |
| 1.9 | Aug 2026 | Platform team | Applicants select an application session before account creation; UTME/DE JAMB and candidate-list checks apply at signup |
| 1.10 | Aug 2026 | Platform team | Document programme workflow templates (UG/JUPEB, transfer, taught PG, research PG) and default-from-study-level rules |
| 1.11 | Aug 2026 | Platform team | Units/subunits inherit department (and subunit inherits unit) portal links; permissions still gate actions |
| 1.11 | Aug 2026 | Platform team | Staff can revert the last admissions decision on an application file |
| 1.12 | Aug 2026 | Platform team | Applicant import does not submit files; applicants must re-upload required documents and submit after login |
| 1.13 | Aug 2026 | Platform team | Admission session lifecycle: create (not current) → open applications → stop accepting → run admission → set current; platform blocks current while intakes accepting |
| 1.14 | Aug 2026 | Platform team | Applicant import posts matching imported application fees or generates an unpaid fee from the fee catalog (session amount is fallback) |
| 1.15 | Aug 2026 | Platform team | Course Core/Elective/Required status; JAMB programme selects from the university catalogue; O’level capped at 9 subjects; application fees set in the fee catalog by entry mode; session/level list filters; students add/drop on a dedicated Course registration page |
| 1.16 | Aug 2026 | Platform team | Removed the staff PG research page; postgraduate applications and research workflows are unchanged |
| 1.17 | Aug 2026 | Platform team | Clinic visit charges come from Finance fee-catalog Clinic services lines; clinic staff attach quantity only; Medical levy remains the programme schedule |
| 1.18 | Aug 2026 | Platform team | Programme-schedule fee items (not only tuition) use 1st–4th 25% installment shares; Programme fees assign per-line amounts and copy a schedule within a college |
| 1.19 | Aug 2026 | Platform team | Every assigned office portal link shows Create/Update/Delete approval settings (not only modules that already have gated actions) |
| 1.24 | Aug 2026 | Platform team | Acceptance fees are set in the fee catalog by entry mode, matching application fees |
| 1.25 | Aug 2026 | Platform team | Hostel level toggles save immediately; student bed requests require ≥25% current-session tuition; Undergraduate and JUPEB windows stay separate |

**Distribution:** Available for download in the staff portal under **System → Resources** by users with the `resources.view` permission.

**Review cycle:** Review quarterly or after major platform releases.

---

## 13. Contact

For access issues, office placement, or security policy changes, contact **ICT Services** or your designated **Super Admin**.

---

*End of document*
