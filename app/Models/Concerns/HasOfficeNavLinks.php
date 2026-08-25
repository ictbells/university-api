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

    /**
     * @return list<array{key: string, require_create: bool, require_update: bool, require_delete: bool, approval_chain: string}>
     */
    public function navLinkConfigs(): array
    {
        return $this->navLinks()
            ->orderBy('nav_key')
            ->get()
            ->map(fn (OfficeNavLink $link) => $link->toConfig())
            ->values()
            ->all();
    }

    /**
     * @param  list<string|array{key?: string, nav_key?: string, require_create?: bool, require_update?: bool, require_delete?: bool, approval_chain?: string}>  $links
     */
    public function syncNavKeys(array $links): void
    {
        $this->syncNavLinks($links);
    }

    /**
     * @param  list<string|array{key?: string, nav_key?: string, require_create?: bool, require_update?: bool, require_delete?: bool, approval_chain?: string}>  $links
     */
    public function syncNavLinks(array $links): void
    {
        $normalized = [];
        foreach ($links as $entry) {
            if (is_string($entry)) {
                $key = $entry;
                $normalized[$key] = [
                    'nav_key' => $key,
                    'require_create' => true,
                    'require_update' => true,
                    'require_delete' => true,
                    'approval_chain' => OfficeNavLink::CHAIN_BOTH,
                ];

                continue;
            }
            if (! is_array($entry)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? $entry['nav_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $chain = (string) ($entry['approval_chain'] ?? OfficeNavLink::CHAIN_BOTH);
            if (! in_array($chain, OfficeNavLink::CHAINS, true)) {
                $chain = OfficeNavLink::CHAIN_BOTH;
            }
            $normalized[$key] = [
                'nav_key' => $key,
                'require_create' => array_key_exists('require_create', $entry) ? (bool) $entry['require_create'] : true,
                'require_update' => array_key_exists('require_update', $entry) ? (bool) $entry['require_update'] : true,
                'require_delete' => array_key_exists('require_delete', $entry) ? (bool) $entry['require_delete'] : true,
                'approval_chain' => $chain,
            ];
        }

        $this->navLinks()->withTrashed()->forceDelete();

        foreach (array_values($normalized) as $row) {
            $this->navLinks()->create($row);
        }
    }
}
