<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Support\Facades\Cache;

class Setting extends BaseModel
{
    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember('setting.'.$key, 60, fn () => static::query()->where('key', $key)->value('value') ?? $default);
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('setting.'.$key);
    }
}
