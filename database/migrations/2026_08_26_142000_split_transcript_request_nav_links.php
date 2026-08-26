<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_KEY = 'transcript-requests';

    private const NEW_KEYS = [
        'transcript-undergraduate',
        'transcript-jupeb',
        'transcript-postgraduate',
    ];

    public function up(): void
    {
        $rows = DB::table('office_nav_links')->where('nav_key', self::OLD_KEY)->get();
        foreach ($rows as $row) {
            foreach (self::NEW_KEYS as $newKey) {
                $payload = [
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('office_nav_links', 'require_create')) {
                    $payload['require_create'] = $row->require_create ?? true;
                    $payload['require_update'] = $row->require_update ?? true;
                    $payload['require_delete'] = $row->require_delete ?? true;
                    $payload['approval_chain'] = $row->approval_chain ?? 'both';
                }
                if (! DB::table('office_nav_links')->where([
                    'linkable_type' => $row->linkable_type,
                    'linkable_id' => $row->linkable_id,
                    'nav_key' => $newKey,
                ])->exists()) {
                    $payload['created_at'] = $row->created_at ?? now();
                    DB::table('office_nav_links')->insert(array_merge([
                        'linkable_type' => $row->linkable_type,
                        'linkable_id' => $row->linkable_id,
                        'nav_key' => $newKey,
                    ], $payload));
                } else {
                    DB::table('office_nav_links')->where([
                        'linkable_type' => $row->linkable_type,
                        'linkable_id' => $row->linkable_id,
                        'nav_key' => $newKey,
                    ])->update($payload);
                }
            }
            DB::table('office_nav_links')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        $groups = DB::table('office_nav_links')
            ->whereIn('nav_key', self::NEW_KEYS)
            ->get()
            ->groupBy(fn ($row) => $row->linkable_type.'|'.$row->linkable_id);

        foreach ($groups as $rows) {
            $first = $rows->first();
            $payload = [
                'linkable_type' => $first->linkable_type,
                'linkable_id' => $first->linkable_id,
                'nav_key' => self::OLD_KEY,
                'created_at' => $first->created_at,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('office_nav_links', 'require_create')) {
                $payload['require_create'] = $first->require_create ?? true;
                $payload['require_update'] = $first->require_update ?? true;
                $payload['require_delete'] = $first->require_delete ?? true;
                $payload['approval_chain'] = $first->approval_chain ?? 'both';
            }
            DB::table('office_nav_links')->insert($payload);
            DB::table('office_nav_links')
                ->whereIn('id', $rows->pluck('id'))
                ->delete();
        }
    }
};
