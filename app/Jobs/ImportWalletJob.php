<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WalletImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportWalletJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public function __construct(
        public string $importId,
        public string $path,
        public int $userId,
    ) {}

    public function handle(WalletImportService $importer): void
    {
        $fullPath = Storage::path($this->path);
        $importer->cacheResult($this->importId, [
            'status' => 'processing',
            'import_id' => $this->importId,
        ]);

        $user = User::query()->find($this->userId);
        if ($user) {
            Auth::login($user);
        }

        try {
            $result = $importer->import($fullPath);
            $importer->cacheResult($this->importId, array_merge($result, [
                'status' => 'done',
                'queued' => true,
                'import_id' => $this->importId,
            ]));
        } catch (Throwable $e) {
            $importer->cacheResult($this->importId, [
                'status' => 'failed',
                'queued' => true,
                'import_id' => $this->importId,
                'posted' => 0,
                'pending' => 0,
                'skipped' => 0,
                'errors' => [['row' => 0, 'matric_number' => '', 'message' => $e->getMessage()]],
                'message' => $e->getMessage(),
            ]);
        } finally {
            Storage::delete($this->path);
        }
    }
}
