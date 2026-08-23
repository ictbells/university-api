<?php

namespace App\Http\Controllers;

use App\Support\OpenApiGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function ui(): View
    {
        return view('api-docs.index', [
            'specUrl' => url('/api/docs/openapi.json'),
            'title' => config('app.name', 'Bells University').' API',
        ]);
    }

    public function spec(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate());
    }
}
