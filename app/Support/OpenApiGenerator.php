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
            'description' => 'Staff with `admissions.view` can edit submitted application fields except application number, NIN, and documents. Email and JAMB registration must stay unique. JAMB is checked against uploaded candidate data (`validated` if found, otherwise `pending`). Change of programme is allowed for 100L–300L; the student level drops by one band except 100L, which stays 100L.',
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
        'get_/api/registrations' => [
            'summary' => 'List registrations',
            'description' => 'Returns paginated student records where the linked application is `matriculated` with a `student_id`, and the student has a paid `tuition` invoice. Requires `registrations.view`. Query filters: `entry_mode`, `entry_modes`.',
            'parameters' => [
                ['name' => 'entry_mode', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Filter by a single entry mode.'],
                ['name' => 'entry_modes', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated entry modes (undergraduate: `utme,de,transfer`; JUPEB: `jupeb`; postgraduate: `pg`).'],
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
            str_starts_with($uri, 'api/programs') => 'Academic',
            str_starts_with($uri, 'api/wallet'),
            str_starts_with($uri, 'api/invoices'),
            str_starts_with($uri, 'api/transactions'),
            str_starts_with($uri, 'api/fees'),
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
            str_starts_with($uri, 'api/pg-records') => 'Postgraduate',
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
