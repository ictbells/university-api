<?php

namespace App\Models;

use App\Support\NinCipher;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStep extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::retrieved(function (ApplicationStep $step) {
            if (is_array($step->payload)) {
                $step->setAttribute('payload', NinCipher::openPayload($step->payload));
                $step->syncOriginalAttribute('payload');
            }
        });
        static::saving(function (ApplicationStep $step) {
            if (is_array($step->payload)) {
                $step->payload = NinCipher::sealPayload($step->payload);
            }
        });
        static::saved(function (ApplicationStep $step) {
            if (is_array($step->payload)) {
                $step->setAttribute('payload', NinCipher::openPayload($step->payload));
                $step->syncOriginalAttribute('payload');
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
