<?php

namespace App\Models\Concerns;

use App\Models\OfficeNavLink;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasOfficeNavLinks
{
    public function navLinks(): MorphMany
    {
        return $this->morphMany(OfficeNavLink::class, 'linkable');
    }

    public function navKeys(): array
    {
        return $this->navLinks()->orderBy('nav_key')->pluck('nav_key')->all();
    }

    public function syncNavKeys(array $keys): void
    {
        $this->navLinks()->withTrashed()->forceDelete();

        foreach (array_values(array_unique($keys)) as $key) {
            $this->navLinks()->create(['nav_key' => $key]);
        }
    }
}
