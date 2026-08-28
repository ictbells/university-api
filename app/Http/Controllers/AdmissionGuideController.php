<?php

namespace App\Http\Controllers;

use App\Models\AdmissionGuide;
use App\Services\AdmissionGuideService;
use Illuminate\Http\Request;

class AdmissionGuideController extends Controller
{
    use Concerns\AuthorizesOfficeApprovals;

    public function __construct(private AdmissionGuideService $guides) {}

    public function publicShow()
    {
        $guide = $this->guides->published();

        return ['guide' => $guide ? $this->guides->payload($guide) : null];
    }

    public function publicPrint(Request $request)
    {
        $guide = $this->guides->published();
        abort_unless($guide, 404, 'No admission guide is published.');

        return $this->htmlResponse($guide, $request);
    }

    public function show()
    {
        return $this->guides->payload($this->guides->current());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'intro' => 'nullable|string|max:20000',
            'sections' => 'nullable|array|max:40',
            'sections.*.heading' => 'nullable|string|max:255',
            'sections.*.body' => 'nullable|string|max:20000',
        ]);
        $guide = $this->guides->current();

        return $this->officeGate(
            'admission_guide.update',
            $guide,
            $data,
            'Update admission guide',
            fn () => $this->guides->update($guide, $data, $request->user()),
        );
    }

    public function publish(Request $request)
    {
        $guide = $this->guides->current();

        return $this->officeGate(
            'admission_guide.publish',
            $guide,
            ['admission_guide_id' => $guide->id],
            'Publish admission guide',
            fn () => $this->guides->publish($guide, $request->user()),
        );
    }

    public function unpublish(Request $request)
    {
        $guide = $this->guides->current();

        return $this->officeGate(
            'admission_guide.unpublish',
            $guide,
            ['admission_guide_id' => $guide->id],
            'Unpublish admission guide',
            fn () => $this->guides->unpublish($guide, $request->user()),
        );
    }

    public function print(Request $request)
    {
        $guide = $this->guides->current();

        return $this->htmlResponse($guide, $request);
    }

    private function htmlResponse(AdmissionGuide $guide, Request $request)
    {
        $html = $this->guides->printHtml($guide);
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="admission-guide.html"';
        }

        return response($html, 200, $headers);
    }
}
