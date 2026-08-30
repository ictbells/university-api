<?php

namespace App\Models;

use App\Support\StudyLevel;
use App\Support\WorkflowCatalog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Program extends BaseModel
{
    protected $fillable = [
        'department_id', 'name', 'code', 'award_type', 'study_level', 'entry_modes',
        'duration_years', 'tuition_amount', 'is_active', 'is_research_degree',
        'eligibility', 'workflow_template_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_modes' => 'array',
            'eligibility' => 'array',
            'is_active' => 'boolean',
            'is_research_degree' => 'boolean',
            'tuition_amount' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'program_course')
            ->withPivot(['academic_level_id', 'bucket'])
            ->withTimestamps();
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function programmeFees(): HasMany
    {
        return $this->hasMany(ProgrammeFee::class);
    }

    /**
     * @return list<string>
     */
    public function entryModeList(): array
    {
        $raw = $this->entry_modes;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded)
                ? $decoded
                : preg_split('/\s*,\s*/', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($mode) => strtolower(trim((string) $mode)),
            $raw,
        )));
    }

    public function acceptsEntryMode(?string $mode): bool
    {
        if (! filled($mode)) {
            return true;
        }
        $modes = $this->entryModeList();
        if ($modes === []) {
            return true;
        }

        return in_array(strtolower(trim($mode)), $modes, true);
    }

    public function isOffered(): bool
    {
        return $this->is_active !== false;
    }

    public function isJupebTrack(): bool
    {
        if (in_array('jupeb', $this->entryModeList(), true)) {
            return true;
        }

        return strtolower((string) $this->study_level) === StudyLevel::JUPEB;
    }

    public function isOfferedAtJupebCentre(): bool
    {
        if (! self::jupebCentresAreConfigured()) {
            return true;
        }

        $this->loadMissing('department.faculty');

        return (bool) $this->department?->faculty?->is_jupeb_centre;
    }

    public function isAvailableForEntryMode(?string $mode): bool
    {
        if (! filled($mode)) {
            return $this->isOffered();
        }
        if (! $this->isOffered()) {
            return false;
        }

        return self::catalogForEntryMode([(string) $mode])->contains(
            fn (self $program) => (int) $program->id === (int) $this->id,
        );
    }

    /**
     * @param  list<string>|string|null  $modes
     * @return EloquentCollection<int, self>
     */
    public static function catalogForEntryMode(array|string|null $modes = null): EloquentCollection
    {
        $modeList = is_array($modes)
            ? $modes
            : (filled($modes) ? [(string) $modes] : []);
        $modeList = array_values(array_filter(array_map(
            fn ($mode) => strtolower(trim((string) $mode)),
            $modeList,
        )));

        $programs = static::query()
            ->with(['department.faculty', 'workflowTemplate.stages'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($modeList === []) {
            return $programs;
        }

        $includeJupeb = in_array('jupeb', $modeList, true);
        $jupebTrack = $includeJupeb
            ? $programs->filter(fn (self $program) => $program->isJupebTrack())
            : collect();

        return $programs->filter(function (self $program) use ($modeList, $includeJupeb, $jupebTrack) {
            foreach ($modeList as $mode) {
                if ($mode === 'jupeb') {
                    if ($program->isJupebTrack()) {
                        return $program->isOfferedAtJupebCentre();
                    }
                    if ($jupebTrack->isEmpty()
                        && StudyLevel::ofProgram($program) === StudyLevel::UNDERGRADUATE) {
                        return $program->isOfferedAtJupebCentre();
                    }

                    continue;
                }
                if ($program->acceptsEntryMode($mode)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    public static function jupebCentresAreConfigured(): bool
    {
        if (! Schema::hasColumn('faculties', 'is_jupeb_centre')) {
            return false;
        }

        return Faculty::query()->where('is_jupeb_centre', true)->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (Program $program) {
            if (filled($program->workflow_template_id)) {
                return;
            }
            $program->workflow_template_id = WorkflowCatalog::ensureDefaultId($program);
        });
    }
}
