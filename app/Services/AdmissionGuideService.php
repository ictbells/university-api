<?php

namespace App\Services;

use App\Models\AdmissionGuide;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdmissionGuideContent;
use App\Support\InstitutionLogo;
use Illuminate\Validation\ValidationException;

class AdmissionGuideService
{
    public function current(): AdmissionGuide
    {
        $guide = AdmissionGuide::query()->latest('id')->first();
        if ($guide) {
            return $guide;
        }

        $sample = AdmissionGuideContent::sample();

        return AdmissionGuide::query()->create([
            'title' => $sample['title'],
            'intro' => $sample['intro'],
            'sections' => $sample['sections'],
            'published_at' => now(),
        ]);
    }

    public function published(): ?AdmissionGuide
    {
        return AdmissionGuide::query()
            ->whereNotNull('published_at')
            ->latest('published_at')
            ->first();
    }

    /**
     * @param  array{title: string, intro?: string|null, sections?: list<array{heading?: string, body?: string}>}  $data
     */
    public function update(AdmissionGuide $guide, array $data, ?User $actor = null): AdmissionGuide
    {
        $before = $guide->replicate();
        $guide->fill([
            'title' => $data['title'],
            'intro' => $data['intro'] ?? '',
            'sections' => $this->normalizedSections($data['sections'] ?? []),
            'updated_by' => $actor?->id,
        ]);
        $guide->save();
        $fresh = $guide->fresh();

        app(AuditWriter::class)->record(
            'admission_guide.updated',
            'Admission guide updated',
            'admissions',
            'admission_guide',
            $guide->id,
            $before,
            $fresh
        );

        return $fresh;
    }

    public function publish(AdmissionGuide $guide, ?User $actor = null): AdmissionGuide
    {
        $this->assertReadyToPublish($guide);
        if ($guide->published_at) {
            return $guide;
        }

        $before = $guide->replicate();
        $guide->update([
            'published_at' => now(),
            'updated_by' => $actor?->id ?? $guide->updated_by,
        ]);
        $fresh = $guide->fresh();

        app(AuditWriter::class)->record(
            'admission_guide.published',
            'Admission guide published',
            'admissions',
            'admission_guide',
            $guide->id,
            $before,
            $fresh
        );

        return $fresh;
    }

    public function unpublish(AdmissionGuide $guide, ?User $actor = null): AdmissionGuide
    {
        abort_unless($guide->published_at, 422, 'The admission guide is not published.');

        $before = $guide->replicate();
        $guide->update([
            'published_at' => null,
            'updated_by' => $actor?->id ?? $guide->updated_by,
        ]);
        $fresh = $guide->fresh();

        app(AuditWriter::class)->record(
            'admission_guide.unpublished',
            'Admission guide unpublished',
            'admissions',
            'admission_guide',
            $guide->id,
            $before,
            $fresh
        );

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(AdmissionGuide $guide): array
    {
        return [
            'id' => $guide->id,
            'title' => $guide->title,
            'intro' => $guide->intro,
            'sections' => $guide->sections ?: [],
            'published_at' => $guide->published_at?->toIso8601String(),
            'updated_at' => $guide->updated_at?->toIso8601String(),
        ];
    }

    public function printHtml(AdmissionGuide $guide): string
    {
        return view('documents.admission-guide', [
            'institution' => [
                'name' => (string) Setting::getValue('university_name', 'Bells University of Technology'),
                'motto' => (string) Setting::getValue('university_motto', 'Chords of Knowledge'),
            ],
            'logo_data_uri' => InstitutionLogo::dataUri(),
            'title' => $guide->title,
            'intro' => $guide->intro,
            'sections' => $guide->sections ?: [],
            'published_at' => $guide->published_at,
            'generated_at' => now()->format('d M Y, h:i A'),
        ])->render();
    }

    /**
     * @param  list<array{heading?: string, body?: string}>  $sections
     * @return list<array{heading: string, body: string}>
     */
    public function normalizedSections(array $sections): array
    {
        $normalized = [];
        foreach ($sections as $section) {
            $heading = trim((string) ($section['heading'] ?? ''));
            $body = trim((string) ($section['body'] ?? ''));
            if ($heading === '' && $body === '') {
                continue;
            }
            $normalized[] = [
                'heading' => $heading !== '' ? $heading : 'Section',
                'body' => $body,
            ];
        }

        return $normalized;
    }

    private function assertReadyToPublish(AdmissionGuide $guide): void
    {
        if (trim((string) $guide->title) === '') {
            throw ValidationException::withMessages([
                'title' => 'Add a title before publishing the guide.',
            ]);
        }
        if ($this->normalizedSections($guide->sections ?? []) === []) {
            throw ValidationException::withMessages([
                'sections' => 'Add at least one section before publishing the guide.',
            ]);
        }
    }
}
