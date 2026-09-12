<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WemaReconcileReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $summary,
        public string $path,
        public string $filename,
    ) {}

    public function envelope(): Envelope
    {
        $university = (string) Setting::getValue('university_name', 'Bells University of Technology');
        $stamp = (string) ($this->summary['generated_at'] ?? now()->toDateTimeString());

        return new Envelope(
            subject: $university.' — Wema payment reconcile report ('.$stamp.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.wema-reconcile-report',
            with: [
                'generatedAt' => (string) ($this->summary['generated_at'] ?? now()->toDateTimeString()),
                'dryRun' => ! empty($this->summary['dry_run']),
                'pendingCount' => (int) ($this->summary['pending_count'] ?? 0),
                'fulfilled' => (int) ($this->summary['fulfilled'] ?? 0),
                'skipped' => (int) ($this->summary['skipped'] ?? 0),
                'noTxId' => (int) ($this->summary['no_tx_id'] ?? 0),
                'failed' => (int) ($this->summary['failed'] ?? 0),
                'unmatched' => (int) ($this->summary['unmatched_count'] ?? 0),
                'prefetched' => (int) ($this->summary['prefetched'] ?? 0),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->path)
                ->as($this->filename)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
