<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ImportsAcademicCatalog
{
    public function catalogImportTemplate(string $type): StreamedResponse
    {
        return $this->catalogImporter->template($type);
    }

    public function runCatalogImport(Request $request, string $type, string $action, string $summary): JsonResponse|array
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        try {
            $result = $this->catalogImporter->import($type, $request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record($action, $summary, 'academic', null, null, null, $result);

        return $result;
    }
}
