# Bells University Staff Portal — Standard Operating Procedure

**Document ID:** SOP-STAFF-PORTAL-001  
**Version:** 1.3  
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
- Academic catalogue, application windows, candidate lists, and applicant import
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

**Admission Setup** contains: Campuses, Colleges, Departments, Sessions & semesters, Levels, Courses.

**Application Setup** contains: Programmes, Application windows, Candidate data, Import applicants, O'level.

**Enrolment** contains: Offerings, Course registration, Unit limits, Registration extensions.

**Fees & payments** contains: Fee catalog, Sundry fees, Rebates, Programme fees, Generate invoice, Invoices, Students Financial Status.

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

When a linked module is owned, ordinary staff mutations do not take effect immediately:

1. **Subunit staff** always go to the parent **unit head** first, then the HOD. If the unit has subunits and no unit head, the action is blocked until a unit head is assigned.
2. **Unit staff** (not the unit head) go to the unit head, then the HOD. If that unit has no head, the request skips to the HOD.
3. **Department-level staff** and the **unit head** skip to the HOD.
4. The **acting HOD** of the owning department, and **Super Admin**, execute immediately.
5. If the department has **no HOD**, any required unit-head step still runs; after that the action executes so rollout is not frozen.

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
| Colleges | Colleges / faculties | `academic.colleges.manage` |
| Departments | Academic departments | `academic.departments.manage` |
| Sessions & semesters | Academic sessions, terms, and end-of-session promotion | `academic.sessions.manage`, `academic.sessions.close` |
| Graduation | Confirm conferment and start the studentship clock | `academic.graduate` |
| Levels | Study levels (100, 200, …) | `academic.levels.manage` |
| Courses | Course catalogue; download template and import spreadsheet | `academic.courses.manage` |

Course import columns: `code`, `title`, `units`, `course_type`, `department_code`, `programme_code`, `level_code`.

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
| Programmes | Programmes, entry modes, eligibility, workflow template | `academic.programmes.manage` |
| Application windows | Open/close intakes by entry mode and session, with application fee | `academic.intakes.manage` |
| Candidate data | Upload JAMB candidate lists used at student signup | `admissions.import` |
| Import applicants | Create applicant accounts and applications from another portal | `admissions.import` |
| O'level | O'level subject catalogue | `academic.olevel.manage` |

#### Candidate data

Upload JAMB candidate spreadsheets **before** new applicants register. Students must match a registration number on this list when policy requires it.

- Formats: `.xlsx`, `.xls`, `.csv`
- Required: registration number (`rg_num`, `registration_number`, and similar headers)
- Optional: candidate name, sex, state, aggregate, course, LGA, UTME subject scores
- Select the academic session on upload

#### Import applicants

Use this when moving people from another admissions portal into this system.

1. Select the **application window** (intake) and **category** (UTME, Direct Entry, JUPEB, Transfer, or Postgraduate). The window's entry mode must match the category.
2. Download the **template** for that category and fill one row per applicant. Do not rename columns.
3. Optionally tick **Verify NIN during upload (Prembly is called for every row)**. Failed NINs are skipped and no account is created. Leave this off to store NIN without live verification; the applicant can verify later in the form.
4. Optionally tick **Email portal passwords** (default on). If the spreadsheet `password` column is filled, that plaintext is hashed and stored and is not emailed unless this box is checked. If the column is blank, a new password is generated and emailed when the box is on.
5. Upload `.xlsx`, `.xls`, or `.csv`. The job is queued when NIN verification is on **or** the file has 40 or more data rows (unless the queue driver is `sync`). Wait for the result summary.
6. Download **failed rows** if any rows were skipped.

**Required columns (all categories):** email, phone, nin, first_name, last_name, first_choice_programme_code. UTME and Direct Entry also require `jamb_registration`.

**Programme codes** must already exist for that entry mode. **Documents are not imported** from Excel; applicants upload remaining files after they sign in.

Complete rows land at stage **`submitted`** (application fee is skipped) so staff can process admission immediately. Incomplete rows stay in the form so the applicant can finish after login.

After import, the applicant signs in on the student portal with **application number or JAMB + password** (not email). Duplicate email, NIN, JAMB, or application number is skipped.

### 8.6 Academic — Enrolment, PG, exam clearance

- **Offerings** — Course offerings per session (`academic.offerings.manage`)
- **Course registration** — Staff view of student enrolments (`academic.enrollments.manage`). Students must have paid at least 25% tuition before self-registering. Staff can still register below that threshold when they provide a reason.
- **Unit limits** — Credit-unit caps (`academic.enrollments.manage`)
- **Registration extensions** — Review late-registration requests (`academic.extensions.review`)
- **PG research** — Postgraduate research records (`pg.view`)
- **Exam clearance** — Exam clearance lists (`exam_clearance.view`)

### 8.7 Services

- **Fees & payments** — Fee catalogue, sundry fees, rebates, programme fees, invoice generation, invoices, and student financial status (`finance.invoices.manage`)
- **Clinic** — Queue, student charts, encounters, prescriptions, sick notes, NHIS-aware billing (`medical.view_any` / `medical.manage` / `medical.billing`)
- **Hostel** — Hostel allocation (`hostel.view`)
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
3. Download the template; fill from the old-portal export. Leave `password` blank unless the old portal provided a reusable plaintext password (never paste a password hash).
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
| Applicant cannot sign in with email | Student portal does not accept email as login | Use application number, JAMB registration, or matric number |
| Import skipped a row | Duplicate email/NIN/JAMB, missing required field, unknown programme code, or NIN verify failed | Download failed rows; fix and re-upload |
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

**Distribution:** Available for download in the staff portal under **System → Resources** by users with the `resources.view` permission.

**Review cycle:** Review quarterly or after major platform releases.

---

## 13. Contact

For access issues, office placement, or security policy changes, contact **ICT Services** or your designated **Super Admin**.

---

*End of document*
