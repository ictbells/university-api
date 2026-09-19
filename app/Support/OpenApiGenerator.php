<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class OpenApiGenerator
{
    /** @var array<string, string> */
    private array $tagDescriptions = [
        'Auth' => 'Authentication, session, profile, and two-factor flows.',
        'Applications' => 'Admissions application pipeline. Staff list endpoints exclude applicants who are already matriculated with paid tuition (see Registrations).',
        'Candidate data' => 'JAMB candidate list upload and lookup used before applicant signup.',
        'Registrations' => 'Enrolled students who completed admission (matriculated) and have paid at least 25% of current-session tuition. Filter by entry mode channel.',
        'Students' => 'Student records.',
        'Academic' => 'Academic catalogue setup (campuses, programmes, courses, sessions, intakes, levels, O\'level) and student academic self-service.',
        'Finance' => 'Fees, invoices, payments, and wallets.',
        'Users & roles' => 'Staff accounts, roles, and permissions.',
        'Institution' => 'Campus structure, institution settings, and office hierarchy.',
        'Approvals' => 'Office unit-head and HOD approval inbox for gated staff mutations.',
        'Medical' => 'Student medical records and clinic visits.',
        'Hostel' => 'Hostel inventory and allocations.',
        'Documents' => 'Issued documents.',
        'Security' => 'Global staff security policies.',
        'Resources' => 'Downloadable operational documents.',
        'Audit' => 'Audit trail.',
        'Reports' => 'Gated custom reporting: allowlisted datasets, saved definitions, and tabular export.',
        'Integrations' => 'External integration status.',
        'Communications' => 'Announcements and notifications.',
        'Postgraduate' => 'Postgraduate records.',
        'Public' => 'Unauthenticated reference data.',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $operationOverrides = [
        'patch_/api/applications/{application}' => [
            'summary' => 'Update application file (staff)',
            'description' => 'Staff with `admissions.view` can edit submitted application fields except application number and documents. `nin` may be corrected (11 digits) and is rejected if already linked to another account; omit or leave blank to keep the current NIN. Names, date of birth, gender, and NIN passport stay locked until staff with `identity.verify_nin` call POST `/api/applications/{application}/nin/resync`. Email and JAMB registration must stay unique. JAMB is checked against uploaded candidate data (`validated` if found, otherwise `pending`). Change of programme is allowed for 100L–300L. Same college: the student keeps the current level, outstanding new-programme courses remain available, and CGPA stays cumulative. Different college: the student drops one band except 100L; the transcript and CGPA keep only old-programme courses below the new level (300L→200L keeps old 100L only) plus results on the new programme.',
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['email', 'first_name', 'last_name', 'first_choice_program_id'],
                            'properties' => [
                                'email' => ['type' => 'string', 'format' => 'email'],
                                'first_name' => ['type' => 'string'],
                                'last_name' => ['type' => 'string'],
                                'nin' => [
                                    'type' => 'string',
                                    'maxLength' => 20,
                                    'description' => 'Optional 11-digit NIN. Staff may correct it. Empty or omitted keeps the current NIN. Must not belong to another applicant or student.',
                                ],
                                'first_choice_program_id' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'post_/api/applications/{application}/nin/resync' => [
            'summary' => 'Resync NIN biodata (staff)',
            'description' => 'Staff with `admissions.view` and `identity.verify_nin` look up the NIN stored on the file and refresh locked names, date of birth, and gender from Prembly. Save a corrected NIN with PATCH `/api/applications/{application}` first. A NIN already linked to another account is rejected.',
            'requestBody' => null,
        ],
        'get_/api/applications' => [
            'summary' => 'List applications (staff pipeline)',
            'description' => 'Returns paginated applications for staff with `admissions.view`. Excludes records that qualify as registrations (matriculated with paid tuition invoice). Query filters: `stage`, `entry_mode`, `entry_modes` (comma-separated, e.g. `utme,de,transfer`).',
            'parameters' => [
                ['name' => 'stage', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by application stage.'],
                ['name' => 'entry_mode', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by a single entry mode (`utme`, `de`, `transfer`, `jupeb`, `pg`).'],
                ['name' => 'entry_modes', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated entry modes for channel views.'],
            ],
        ],
        'delete_/api/applications/{application}' => [
            'summary' => 'Delete application file (staff)',
            'description' => 'Staff with `admissions.delete` soft-delete one application without removing the shared applicant account. Use this when the same person bought more than one form (for example postgraduate and UTME) and those files share email, phone, and JAMB. Paid invoices are kept; unpaid invoices for this file are cancelled. Blocked after an offer is issued, acceptance is paid, physical clearance, or a student record exists. Requires a reason. Office delete-approval still applies where configured.',
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['reason'],
                            'properties' => [
                                'reason' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 500],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'get_/api/applications/{application}/offer-letter' => [
            'summary' => 'Print admission letter',
            'description' => 'Returns the official HTML admission letter for an issued offer. Undergraduate letters use the Registry wording. JUPEB letters use the Foundation Programme wording. Postgraduate letters use College of Postgraduate Studies provisional-admission wording (COLPGS reference, 50%/50% fee split). Signatory name, title, and signature come from Application settings (registrar for UG/JUPEB; postgraduate signatory for PG).',
        ],
        'get_/api/applications/clearance' => [
            'summary' => 'List applicants for physical clearance',
            'description' => 'Applicants who have paid acceptance and are waiting to be cleared on campus, or already cleared. Requires `admissions.view` or `admissions.clear`. Query filters match the applications list, plus `status` (`pending` default, or `cleared`).',
            'parameters' => [
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['pending', 'cleared']], 'description' => 'Default `pending`.'],
                ['name' => 'entry_modes', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'academic_session_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ],
        'post_/api/applications/{application}/clear' => [
            'summary' => 'Clear one admitted applicant',
            'description' => 'Records physical clearance after acceptance payment and creates or reattaches the student record. Undergraduate, DE, transfer, and postgraduate students are emailed their matric number for student-portal sign-in. Requires `admissions.clear`.',
        ],
        'post_/api/applications/clearance/bulk' => [
            'summary' => 'Clear admitted applicants in bulk',
            'description' => 'Clears eligible applicants by `ids`. Ineligible rows are skipped. Newly created undergraduate, DE, transfer, and postgraduate students are emailed their matric number. Requires `admissions.clear`. Body: `ids` (1–200 application ids).',
        ],
        'get_/api/registrations' => [
            'summary' => 'List registrations',
            'description' => 'Returns paginated student records where the linked application is `matriculated` with a `student_id`, and the student has a paid `tuition` invoice. Requires `registrations.view`. Query filters: `entry_mode`, `entry_modes`, `studentship` (`current` default, `alumni`, or `all`).',
            'parameters' => [
                ['name' => 'entry_mode', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by a single entry mode.'],
                ['name' => 'entry_modes', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated entry modes (undergraduate: `utme,de,transfer`; JUPEB: `jupeb`; postgraduate: `pg`).'],
                ['name' => 'studentship', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['current', 'alumni', 'all']], 'description' => 'Default `current` (active or graduated with unexpired studentship).'],
            ],
        ],
        'get_/api/academic/graduation/candidates' => [
            'summary' => 'List graduation candidates',
            'description' => 'Active students at programme final level. Requires `academic.graduate`. Query filters: `program_id`, `campus_id`, `search`.',
        ],
        'post_/api/academic/graduation/confer' => [
            'summary' => 'Confirm graduation (conferment)',
            'description' => 'Sets `graduated_at`, `studentship_expires_at`, and status `graduated`. Starts the studentship clock. Requires `academic.graduate`. Body: `student_ids`, `graduated_at`, optional `academic_session_id`, `require_final_year` (default true).',
        ],
        'post_/api/students/{student}/confer' => [
            'summary' => 'Confirm graduation for one student',
            'description' => 'Late senate conferment; final year is not required. Requires `academic.graduate`. Body: `graduated_at`.',
        ],
        'get_/api/receipts/{receipt_no}/verify' => [
            'summary' => 'Verify a payment receipt',
            'description' => 'Public signed URL encoded in the receipt QR code. Confirms receipt number, payer, amount, date, and PAID status for a successful payment. Unsigned or tampered links return 403. Unknown or unsuccessful receipts return a branded HTML failure page.',
            'parameters' => [
                ['name' => 'receipt_no', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'signature', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Non-expiring HMAC from Laravel signed URLs.'],
            ],
        ],
        'get_/api/candidate-data/{jambRegistration}' => [
            'summary' => 'Lookup candidate by JAMB number',
            'description' => 'Public lookup used during applicant signup. Returns candidate row and suggested name/UTME prefill payload.',
            'parameters' => [
                ['name' => 'jambRegistration', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'academic_year', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Optional session filter.'],
            ],
        ],
        'post_/api/candidate-data/upload' => [
            'summary' => 'Upload candidate spreadsheet',
            'description' => 'Import JAMB candidate rows from Excel/CSV. Requires `admissions.import`. Multipart fields: `file`, `academic_year`.',
        ],
        'get_/api/applicants/import-template' => [
            'summary' => 'Download applicant import template',
            'description' => 'Excel template for a single applicant category (`entry_mode`). Requires `admissions.import`.',
            'parameters' => [
                ['name' => 'entry_mode', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['utme', 'de', 'jupeb', 'transfer', 'pg']]],
            ],
        ],
        'post_/api/applicants/import' => [
            'summary' => 'Import applicants from spreadsheet',
            'description' => 'Create applicant accounts and applications from Excel/CSV. Requires `admissions.import`. Multipart fields: `file`, `intake_id`, `entry_mode`, `verify_nin`, `send_credentials`. Large files and NIN verification are queued.',
        ],
        'post_/api/jupeb/matric/assign' => [
            'summary' => 'Assign JUPEB matric number',
            'description' => 'Staff with `admissions.matriculate` (or `students.manage`) assign an official JUPEB matric number to a student who does not yet have one. Body: `matric_number` plus `student_id`, or `application_number` / `student_number` / `email` / `nin`. The student is emailed the matric number for student-portal sign-in (existing password).',
        ],
        'post_/api/jupeb/matric/import' => [
            'summary' => 'Import JUPEB matric numbers',
            'description' => 'Upload an Excel/CSV of JUPEB matric numbers (`file`). Each newly assigned student is emailed the matric number for student-portal sign-in. Requires `admissions.matriculate` or `students.manage`. Download the template from GET `/api/jupeb/matric/template`.',
        ],
        'get_/api/jupeb/matric/pending' => [
            'summary' => 'List JUPEB students without a matric number',
            'description' => 'Students on the JUPEB track with a blank matric number. Requires `admissions.matriculate` or `students.manage`.',
        ],
        'post_/api/finance/semester-fee/generate' => [
            'summary' => 'Generate semester fee for all enrolled students',
            'description' => 'Creates one unpaid wallet invoice per active enrolled student for the selected academic term (defaults to the current term). Uses the single active `semester_fee` catalog FeeItem amount. Applicants and inactive students are skipped. Re-running for the same term skips students already billed. Students who were not included also receive the invoice automatically the next time they open wallet, transactions, or financial status while a current academic term is set and the catalog amount is greater than zero. Requires `finance.invoices.manage`. May return HTTP 202 when Fees & payments Create office approval is required. Pay-first: when a semester is marked current and the student has not paid that term\'s semester fee, other invoice payments and tuition installment creation are blocked; wallet top-up remains allowed. With no current semester, pay-first does not apply.',
            'requestBody' => [
                'required' => false,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'academic_term_id' => [
                                    'type' => 'integer',
                                    'description' => 'Optional academic term id. Defaults to the current term.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'get_/api/fees/meta' => [
            'summary' => 'Fee catalog metadata',
            'description' => 'Categories, installment options, transcript types, and semester-fee catalog amount plus recent academic terms for bulk generation. Requires `finance.invoices.manage`.',
        ],
        'get_/api/my-programme-fees' => [
            'summary' => 'Student programme fee schedule',
            'description' => 'Current-session tuition schedule, available installment percents, prior unpaid arrears, and semester-fee pay-first flags (`semester_fee_required`, `semester_fee_invoice_id`, `semester_fee_balance`, `semester_fee_amount`, `semester_fee_term_id`, `semester_fee_error`). `semester_fee_required` is true only when a current academic term is set, the catalog semester fee is active (amount > 0), and the student has not paid that term\'s fee. With no current term, `semester_fee_required` is false and other payments proceed.',
        ],
        'get_/api/wallet' => [
            'summary' => 'Student campus wallet',
            'description' => 'Wallet balance, recent transactions, outstanding arrears, and the same semester-fee pay-first flags as `GET /api/my-programme-fees`. Auto-bills the current-term semester fee when a current term is set and the catalog amount is greater than zero.',
        ],
        'post_/api/invoices/tuition-installment' => [
            'summary' => 'Create tuition installment invoice',
            'description' => 'Student creates the next unpaid tuition installment. Blocked when prior-session arrears remain, or when a current academic term is set and the current-term semester fee is unpaid/partial (or could not be billed). With no current semester, the semester-fee gate does not apply.',
        ],
        'post_/api/wallet/pay/{invoice}' => [
            'summary' => 'Pay invoice from campus wallet',
            'description' => 'Debits the student wallet to settle an invoice. When a current academic term is set and the current-term semester fee is unpaid, other categories are blocked until that semester fee is paid (paying the semester fee itself is always allowed). With no current semester, other wallet payments proceed. Application, acceptance, and transcript fees cannot be paid from wallet.',
        ],
        'post_/api/payments/initialize' => [
            'summary' => 'Initialize online payment',
            'description' => 'Starts gateway checkout for an invoice or wallet top-up. For student invoices (not application/acceptance), the same semester-fee pay-first rule as wallet pay applies when a current term is set. Wallet top-up is never blocked by semester fee.',
        ],
        'patch_/api/terms/{term}' => [
            'summary' => 'Update academic semester',
            'description' => 'Update semester dates, registration windows, auto-schedule, or mark `is_current`. Setting current is allowed even while application sessions (intakes) on the parent admission session are still accepting. Only one semester is current at a time.',
        ],
        'get_/api/academic/sessions' => [
            'summary' => 'List admission sessions',
            'description' => 'Academic sessions with nested semesters. Each row includes `accepting_application_sessions` (open intake names for awareness) and `can_set_current` (always true — open applications do not block marking a semester current).',
        ],
    ];

    public function generate(): array
    {
        $paths = [];
        $tagNames = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->isApiRoute($route)) {
                continue;
            }

            $path = '/'.$route->uri();
            $methods = array_values(array_filter(
                $route->methods(),
                fn (string $method) => ! in_array($method, ['HEAD', 'OPTIONS'], true),
            ));

            foreach ($methods as $method) {
                $httpMethod = strtolower($method);
                $tag = $this->tagForRoute($route);
                $tagNames[$tag] = true;
                $permission = $this->permissionForRoute($route);
                $requiresAuth = $this->requiresAuth($route);
                $action = $route->getActionName();

                $operation = [
                    'operationId' => $this->operationId($route, $httpMethod),
                    'summary' => $this->summary($route, $httpMethod),
                    'tags' => [$tag],
                    'responses' => $this->responses(),
                ];

                if ($permission) {
                    $operation['description'] = 'Requires permission: `'.$permission.'`.';
                }

                if ($requiresAuth) {
                    $operation['security'] = [['bearerAuth' => []]];
                }

                if (in_array($httpMethod, ['post', 'put', 'patch'], true)) {
                    $operation['requestBody'] = [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['type' => 'object'],
                            ],
                        ],
                    ];
                }

                if (! isset($paths[$path])) {
                    $paths[$path] = [];
                }

                $paths[$path][$httpMethod] = $this->enrichOperation(
                    $operation,
                    $httpMethod,
                    $path,
                );
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name', 'Bells University').' API',
                'description' => 'Self-hosted OpenAPI documentation generated from registered Laravel routes. Authenticated endpoints use Laravel Sanctum bearer tokens obtained from `POST /api/login`.',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/'), 'description' => 'API server'],
            ],
            'tags' => collect(array_keys($tagNames))
                ->sort()
                ->values()
                ->map(fn (string $name) => [
                    'name' => $name,
                    'description' => $this->tagDescriptions[$name] ?? null,
                ])
                ->all(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum',
                        'description' => 'Send `Authorization: Bearer {token}` where the token is returned by `POST /api/login` or registration.',
                    ],
                ],
            ],
        ];
    }

    private function isApiRoute(Route $route): bool
    {
        return str_starts_with($route->uri(), 'api/');
    }

    private function requiresAuth(Route $route): bool
    {
        return collect($route->gatherMiddleware())->contains(
            fn (string $middleware) => str_contains($middleware, 'auth:sanctum') || str_contains($middleware, 'Authenticate:sanctum'),
        );
    }

    private function permissionForRoute(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'permission:')) {
                return Str::after($middleware, 'permission:');
            }
            if (str_contains($middleware, 'EnsurePermission:')) {
                return Str::after($middleware, 'EnsurePermission:');
            }
            if (str_starts_with($middleware, 'academic.resource:')) {
                $keys = Str::after($middleware, 'academic.resource:');

                return 'academic resource access ('.$keys.')';
            }
            if (str_starts_with($middleware, 'portal.nav:')) {
                return 'office portal link `'.Str::after($middleware, 'portal.nav:').'`';
            }
        }

        return null;
    }

    private function tagForRoute(Route $route): string
    {
        $uri = $route->uri();

        return match (true) {
            str_starts_with($uri, 'api/login'),
            str_starts_with($uri, 'api/logout'),
            str_starts_with($uri, 'api/register'),
            str_starts_with($uri, 'api/forgot-password'),
            str_starts_with($uri, 'api/reset-password'),
            str_starts_with($uri, 'api/two-factor'),
            str_starts_with($uri, 'api/me') => 'Auth',
            str_starts_with($uri, 'api/applications') => 'Applications',
            str_starts_with($uri, 'api/candidate-data'),
            str_starts_with($uri, 'api/candidate-list') => 'Candidate data',
            str_starts_with($uri, 'api/applicants') => 'Applications',
            str_starts_with($uri, 'api/registrations') => 'Registrations',
            str_starts_with($uri, 'api/students') => 'Students',
            str_starts_with($uri, 'api/academic'),
            str_starts_with($uri, 'api/programs'),
            str_starts_with($uri, 'api/jupeb') => 'Academic',
            str_starts_with($uri, 'api/wallet'),
            str_starts_with($uri, 'api/invoices'),
            str_starts_with($uri, 'api/receipts'),
            str_starts_with($uri, 'api/transactions'),
            str_starts_with($uri, 'api/fees'),
            str_starts_with($uri, 'api/finance'),
            str_starts_with($uri, 'api/payments') => 'Finance',
            str_starts_with($uri, 'api/users'),
            str_starts_with($uri, 'api/roles'),
            str_starts_with($uri, 'api/permissions') => 'Users & roles',
            str_starts_with($uri, 'api/office-approvals') => 'Approvals',
            str_starts_with($uri, 'api/office-'),
            str_starts_with($uri, 'api/staff-nav'),
            str_starts_with($uri, 'api/institution'),
            str_starts_with($uri, 'api/campuses'),
            str_starts_with($uri, 'api/faculties'),
            str_starts_with($uri, 'api/departments'),
            str_starts_with($uri, 'api/terms') => 'Institution',
            str_starts_with($uri, 'api/medical'),
            str_starts_with($uri, 'api/clinic-visits') => 'Medical',
            str_starts_with($uri, 'api/hostel') => 'Hostel',
            str_starts_with($uri, 'api/documents') => 'Documents',
            str_starts_with($uri, 'api/security-settings') => 'Security',
            str_starts_with($uri, 'api/resources') => 'Resources',
            str_starts_with($uri, 'api/audit-logs') => 'Audit',
            str_starts_with($uri, 'api/reports') => 'Reports',
            str_starts_with($uri, 'api/integrations') => 'Integrations',
            str_starts_with($uri, 'api/announcements'),
            str_starts_with($uri, 'api/notifications') => 'Communications',
            default => 'Public',
        };
    }

    private function operationId(Route $route, string $method): string
    {
        $action = $route->getActionName();
        if (str_contains($action, '@')) {
            [$controller, $handler] = explode('@', class_basename($action));

            return Str::camel($method.'_'.Str::snake(str_replace('Controller', '', $controller)).'_'.$handler);
        }

        return Str::camel($method.'_'.Str::slug($route->uri(), '_'));
    }

    private function summary(Route $route, string $method): string
    {
        $action = $route->getActionName();
        if (str_contains($action, '@')) {
            [, $handler] = explode('@', class_basename($action));
            $verb = match ($method) {
                'get' => 'Get',
                'post' => 'Create',
                'put' => 'Replace',
                'patch' => 'Update',
                'delete' => 'Delete',
                default => strtoupper($method),
            };

            return trim($verb.' '.Str::headline($handler));
        }

        return strtoupper($method).' '.$route->uri();
    }

    private function enrichOperation(array $operation, string $httpMethod, string $path): array
    {
        $key = $httpMethod.'_'.$path;
        $override = $this->operationOverrides[$key] ?? null;
        if (! $override) {
            return $operation;
        }

        if (isset($override['summary'])) {
            $operation['summary'] = $override['summary'];
        }
        if (isset($override['description'])) {
            $operation['description'] = $override['description'];
        }
        if (isset($override['parameters'])) {
            $operation['parameters'] = $override['parameters'];
        }
        if (array_key_exists('requestBody', $override)) {
            if ($override['requestBody'] === null) {
                unset($operation['requestBody']);
            } else {
                $operation['requestBody'] = $override['requestBody'];
            }
        }

        return $operation;
    }

    private function responses(): array
    {
        return [
            '200' => ['description' => 'Successful response'],
            '201' => ['description' => 'Created'],
            '204' => ['description' => 'No content'],
            '401' => ['description' => 'Unauthenticated'],
            '403' => ['description' => 'Forbidden or permission denied'],
            '404' => ['description' => 'Not found'],
            '422' => ['description' => 'Validation error'],
        ];
    }
}
