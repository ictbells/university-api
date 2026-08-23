# Bells University Staff Portal — Standard Operating Procedure

**Document ID:** SOP-STAFF-PORTAL-001  
**Version:** 1.1  
**Effective date:** August 2026  
**Audience:** ICT administrators, registrars, office heads, and authorised staff  
**Classification:** Internal use only

---

## 1. Purpose

This Standard Operating Procedure (SOP) describes the Bells University staff portal: what has been implemented, how access is controlled, and how administrators should configure and operate the platform.

---

## 2. Scope

This document covers:

- Staff portal sign-in, navigation, and profile management
- Role-based permissions and office-based sidebar scoping
- Office structure (departments, units, sub-units) and nav link assignment
- User management and office placement
- Global application security settings (2FA, password rotation, inactivity logout)
- Core functional modules exposed in the staff portal
- Audit and reporting capabilities

**Out of scope:** Student/applicant portal workflows (separate application), payment gateway configuration, and server infrastructure.

---

## 3. System overview

The platform consists of:

| Component | Description |
|-----------|-------------|
| **Staff portal** | Web application for university staff (React frontend) |
| **Student portal** | Separate sign-in for applicants and enrolled students |
| **API** | Laravel backend providing authentication, permissions, and business logic |
| **Database** | Stores users, roles, office structure, settings, and operational records |

Staff and students use **separate portals**. An account is routed to the correct portal at sign-in based on assigned roles and staff records.

---

## 4. Roles and permissions

### 4.1 Permission model

Access to features is controlled by **permissions** (e.g. `users.manage`, `admissions.view`). Permissions are grouped into **roles** (e.g. Super Admin, Registrar, Admissions). Users may hold one or more roles.

**Super Admin** (`super-admin` role) has unrestricted access to all permissions and all sidebar links.

### 4.2 Managing roles and permissions

| Task | Location | Required permission |
|------|----------|---------------------|
| View/edit roles | Administration → Roles | `roles.manage` |
| View permission catalogue | Administration → Permissions | `roles.manage` |
| Assign roles to users | Administration → Users | `users.manage` |

When creating or editing a role, tick the permissions that role should grant. Changes apply immediately to all users holding that role.

### 4.3 Standard roles (seeded)

| Role | Typical access |
|------|----------------|
| **Super Admin** | Full access to all permissions and sidebar links |
| **Registrar** | SIS, academic setup, applications, registrations, reports, institution |
| **Admissions** | Applications pipeline, application setup (programmes, intakes, O'level), registrations view |
| **Finance** | Fees, payments, wallet |
| **Medical** | Medical module |
| **Faculty** | Student view |
| **PG Coordinator** | Postgraduate records and applications view |
| **Hostel Officer** | Hostel module |

### 4.4 Applications and registrations permissions

| Permission | Purpose |
|------------|---------|
| `admissions.view` | View and process the **Applications** pipeline |
| `admissions.screen` … `admissions.matriculate` | Advance applications through admission stages |
| `registrations.view` | View **Registrations** (matriculated students with paid tuition) |

Staff need **both** the role permission and an office portal link for each sidebar item (except Super Admin).

### 4.5 Academic setup permissions

Academic catalogue pages use **per-resource** permissions instead of one broad academic permission:

| Permission | Sidebar item |
|------------|--------------|
| `academic.campuses.manage` | Campuses |
| `academic.colleges.manage` | Colleges |
| `academic.departments.manage` | Departments |
| `academic.sessions.manage` | Sessions |
| `academic.levels.manage` | Levels |
| `academic.courses.manage` | Courses |
| `academic.programmes.manage` | Programmes |
| `academic.intakes.manage` | Application windows |
| `academic.olevel.manage` | O'level subjects |

Legacy permissions (`institution.manage`, `academic.catalog.manage`) still grant access to the corresponding setup areas for backward compatibility.

`institution.manage` is required for **Office setup** and **Institution** administration pages.

---

## 5. Staff sign-in and account security

### 5.1 Sign-in

1. Open the staff portal URL.
2. Enter work **email** and **password**.
3. If two-factor authentication (2FA) is enabled globally, complete the authenticator step after password verification.
4. On first login with 2FA enabled, scan the provided secret into an authenticator app (Google Authenticator, Microsoft Authenticator, etc.) and enter the 6-digit code.

Applicants and students must use the **student portal**, not the staff portal.

### 5.2 Password reset

1. On the sign-in page, click **Forgot password?**
2. Enter the registered email address.
3. Follow the link in the reset email to set a new password.

### 5.3 Profile

Staff may update name, phone, job title, and password under **Profile** (account menu, top right). Email changes require ICT support.

### 5.4 Global security policies (Application settings)

Users with `settings.manage` can configure policies under **System → Application settings**. These apply to **all staff** immediately:

| Policy | Options | Effect |
|--------|---------|--------|
| **Two-factor authentication** | On / Off | When on, every staff member must use TOTP at sign-in |
| **Password rotation** | Off, 30, 60, 90, or 180 days | Staff must change password when the interval expires |
| **Inactivity logout** | Off, 15, 30, 60, or 120 minutes | Staff are signed out after idle time with no activity |

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

### 6.2 Office hierarchy

Offices are organised in three levels:

```
Office Department  →  Office Unit  →  Office Sub-unit
```

Example:

- Registry (department)
  - Student Records (unit)
    - Transcript Desk (sub-unit)

Configure structure under **Administration → Office setup**.

### 6.3 Assigning sidebar links to an office

1. Go to **Office setup**.
2. Locate the department, unit, or sub-unit row.
3. Click **Links**.
4. Tick the sidebar items that office should access.
5. Save.

Staff placed at that node will see only those links (plus Home), subject to their role permissions.

**Portal link keys** for admissions workflows:

| Section | Nav keys | Staff route |
|---------|----------|-------------|
| Applications → Undergraduate | `admissions-undergraduate` | `/applications/undergraduate` |
| Applications → JUPEB | `admissions-jupeb` | `/applications/jupeb` |
| Applications → Postgraduate | `admissions-postgraduate` | `/applications/postgraduate` |
| Registrations → Undergraduate | `registrations-undergraduate` | `/registrations/undergraduate` |
| Registrations → JUPEB | `registrations-jupeb` | `/registrations/jupeb` |
| Registrations → Postgraduate | `registrations-postgraduate` | `/registrations/postgraduate` |

Legacy `/admissions/*` URLs redirect to `/applications/*`.

### 6.4 Assigning staff to an office

1. Go to **Administration → Users**.
2. Create or edit a user.
3. Set **office placement** (department, unit, or sub-unit).
4. Save.

If placement points to a deleted or invalid office node, the system clears stale placement on login and the user will see **Home only** until reassigned.

### 6.5 Clearing office placement

On the Users page, use **Clear office placement** to remove a staff member from all office nodes. They will retain role permissions but lose scoped sidebar links (Home only, unless Super Admin).

---

## 7. User management

| Task | Steps |
|------|-------|
| Create staff user | Users → Add user; set name, email, password, roles, optional staff title and office placement |
| Disable user | Edit user → set status to disabled (reason required) |
| Reset password (admin) | Edit user → enter new password |
| Assign roles | Edit user or use role assignment with audit reason |

Disabled users cannot sign in. All significant user changes are recorded in the audit trail.

---

## 8. Staff portal modules

The following areas are available in the staff portal, subject to permissions and office nav links:

### 8.1 Overview

- **Home** — Dashboard with welcome message, optional summary stats (`reports.view`), and quick access links
- **Students** — Student records (`students.view_any`)

### 8.2 Applications

The **Applications** section is the active admissions pipeline, split by entry channel:

- **Undergraduate** — UTME, Direct Entry, and Transfer (`admissions.view`)
- **JUPEB** — JUPEB applicants (`admissions.view`)
- **Postgraduate** — PG applicants (`admissions.view`)

Staff can advance applications through screening, verification, shortlisting, recommendation, approval, offer, and matriculation stages using stage-specific permissions (`admissions.screen` through `admissions.matriculate`).

Applicants who are **matriculated and have paid tuition** are removed from Applications lists and appear under Registrations instead.

### 8.3 Registrations

The **Registrations** section lists students who have:

1. **Completed admission** — application `stage` is `matriculated` and linked to a student record
2. **Paid tuition** — a paid invoice with category `tuition`

Channels mirror Applications (Undergraduate, JUPEB, Postgraduate) and require `registrations.view` plus the matching office portal link.

### 8.4 Academic

Academic setup is grouped under two dropdown menus:

**Admission Setup**

- Campuses, Colleges, Departments, Sessions, Levels, Courses

**Application Setup**

- Programmes, Application windows, O'level subjects

Each item requires its own `academic.*.manage` permission and portal link (see §4.5).

- **PG research** — Postgraduate records (`pg.view`)

### 8.5 Services

- **Fees & payments** — Fee items, invoices, payments (`finance.invoices.manage`)
- **Medical** — Student medical records (`medical.view_any`)
- **Hostel** — Hostel allocation (`hostel.view`)
- **Documents** — Issue documents (`documents.issue`)

### 8.6 Administration

- **Users** — Staff account management (`users.manage`)
- **Roles / Permissions** — Access control (`roles.manage`)
- **Office setup** — Office hierarchy and nav links (`institution.manage`)
- **Institution** — University settings (`institution.manage`)

### 8.7 System

- **Audit** — Activity log (`audit.view`)
- **Reports** — Summary reports (`reports.view`)
- **Notifications / Announcements** — Campus communications
- **Integrations** — External endpoints (`integrations.view`)
- **Application settings** — Global security policies (`settings.manage`)
- **Resources** — Download operational documents such as this SOP (`resources.view`)
- **API documentation** — Self-hosted Swagger UI at `/api/docs` (OpenAPI spec generated from routes; includes `GET /api/registrations`)

---

## 9. Audit trail

Actions such as sign-in, user changes, role updates, and profile edits are logged.

- **View logs:** System → Audit (`audit.view`)
- Logs include actor, action, entity, timestamp, and optional reason (e.g. user disable)

Use the audit trail for compliance reviews and incident investigation.

---

## 10. Standard operating workflows

### 10.1 Onboard a new admissions officer

1. Create user in **Users** with Admissions role (includes `admissions.view` and `registrations.view`).
2. In **Office setup**, ensure the admissions office has Applications links (`admissions-undergraduate`, `admissions-jupeb`, `admissions-postgraduate`) and, if needed, Registrations links (`registrations-*`) ticked.
3. Assign the user to the correct office department/unit/sub-unit.
4. Confirm the user can sign in and sees the expected sidebar items.
5. If 2FA is enabled, ensure they complete authenticator setup on first login.

### 10.2 Configure registrations access for student records desk

1. Grant the role `registrations.view` (Registrar and Admissions roles include this by default).
2. In **Office setup → Links**, tick the required Registrations channel links for that office.
3. Verify staff see enrolled, tuition-paid students under **Registrations**, not under **Applications**.

### 10.3 Restrict a unit to specific functions

1. Identify the office unit in **Office setup**.
2. Open **Links** and select only the required modules.
3. Assign staff only to that unit (not a broader parent node unless intended).
4. Verify each staff member's role includes permissions for the assigned links.

### 10.4 Enable organisation-wide 2FA

1. Sign in as a user with `settings.manage`.
2. Open **Application settings**.
3. Enable **Require 2FA for staff**.
4. Save.
5. Notify all staff to install an authenticator app before their next sign-in.

### 10.5 Investigate “staff sees no links”

1. Confirm user is not Super Admin (office scoping applies).
2. Check **Users** → office placement is set and not stale.
3. Check **Office setup** → **Links** on the assigned node include expected items.
4. Confirm user's role grants the permission for each link (e.g. `admissions.view` for Applications, `registrations.view` for Registrations, `academic.programmes.manage` for Programmes).

---

## 11. Troubleshooting

| Issue | Likely cause | Resolution |
|-------|--------------|------------|
| “Student portal required” on staff URL | Account has applicant/student role only | Use student portal or assign staff role |
| Only Home visible | No office links configured or stale placement | Assign office + configure links |
| 2FA code rejected | Clock drift or wrong secret | Re-sync device time; restart 2FA setup if needed |
| Signed out unexpectedly | Inactivity policy | Sign in again; adjust policy in Application settings if appropriate |
| Password change blocked | Rotation policy expired | Set new password via profile prompt |
| Cannot access Application settings | Missing `settings.manage` | Super Admin or ICT assigns permission |

---

## 12. Document control

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | Aug 2026 | Platform team | Initial release covering staff portal, office nav, and security settings |
| 1.1 | Aug 2026 | Platform team | Applications/Registrations split, academic setup permissions, registrations API |

**Distribution:** Available for download in the staff portal under **System → Resources** by users with the `resources.view` permission.

**Review cycle:** Review quarterly or after major platform releases.

---

## 13. Contact

For access issues, office placement, or security policy changes, contact **ICT Services** or your designated **Super Admin**.

---

*End of document*
