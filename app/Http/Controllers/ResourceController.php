<?php

namespace App\Http\Controllers;

use App\Services\ResourceRenderer;
use App\Support\ResourceCatalog;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ResourceController extends Controller
{
    public function __construct(private ResourceRenderer $renderer) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = collect(ResourceCatalog::all())
            ->filter(fn (array $resource) => $user->hasPermission($resource['permission']))
            ->map(fn (array $resource) => $this->summary($resource))
            ->values();

        return response()->json($items);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $resource = $this->authorized($request, $slug);
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        return response()->json([
            ...$this->summary($resource),
            'content_markdown' => $this->renderer->markdown($resource),
            'content_html' => $this->renderer->html($resource),
        ]);
    }

    public function download(Request $request, string $slug): BinaryFileResponse|JsonResponse
    {
        $resource = $this->authorized($request, $slug);
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $path = ResourceCatalog::path($resource);
        if (! is_file($path)) {
            return response()->json(['message' => 'Resource file is unavailable.'], 404);
        }

        return response()->download($path, $resource['filename'], [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function downloadPdf(Request $request, string $slug): Response|JsonResponse
    {
        $resource = $this->authorized($request, $slug);
        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        $html = view('resources.pdf', [
            'title' => $resource['title'],
            'version' => $resource['version'],
            'updated_at' => $resource['updated_at'],
            'content' => $this->renderer->html($resource),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = str_replace('.md', '.pdf', $resource['filename']);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function authorized(Request $request, string $slug): array|JsonResponse
    {
        $resource = ResourceCatalog::find($slug);
        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        if (! $request->user()->hasPermission($resource['permission'])) {
            return response()->json(['message' => 'You do not have permission to view this resource.'], 403);
        }

        if (! is_file(ResourceCatalog::path($resource))) {
            return response()->json(['message' => 'Resource file is unavailable.'], 404);
        }

        return $resource;
    }

    private function summary(array $resource): array
    {
        return [
            'slug' => $resource['slug'],
            'title' => $resource['title'],
            'description' => $resource['description'],
            'version' => $resource['version'],
            'updated_at' => $resource['updated_at'],
            'filename' => $resource['filename'],
        ];
    }
}
