<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Models\User;
use App\Services\StudentImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportStudentsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public function __construct(
        public string $importId,
        public string $path,
        public int $intakeId,
        public string $entryMode,
        public bool $verifyNin,
        public bool $sendCredentials,
        public int $userId,
    ) {}

    public function handle(StudentImportService $importer): void
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
            $intake = Intake::query()->findOrFail($this->intakeId);
            $result = $importer->import($fullPath, $intake, $this->entryMode, [
                'verify_nin' => $this->verifyNin,
                'send_credentials' => $this->sendCredentials,
            ]);
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
                'created' => 0,
                'skipped' => 0,
                'emailed' => 0,
                'nin_failed' => 0,
                'invoices_posted' => 0,
                'wallet_posted' => 0,
                'errors' => [['row' => 0, 'email' => '', 'nin' => '', 'matric_number' => '', 'message' => $e->getMessage()]],
                'message' => $e->getMessage(),
            ]);
        } finally {
            Storage::delete($this->path);
        }
    }
}
