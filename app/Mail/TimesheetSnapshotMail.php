<?php

namespace App\Mail;

use App\Domain\Attendance\Models\Timesheet;
use App\Domain\Shared\Models\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * TIMESHEET-EMAIL-01: carries the already-generated, immutable PDF snapshot
 * (an `attachments` row) as a real attachment — never regenerates the PDF
 * itself, so what's attached is byte-for-byte what
 * App\Domain\Timesheets\Actions\SendTimesheetEmailAction stored at send
 * time, regardless of any later edit to the Timesheet.
 *
 * Deliberately does NOT implement ShouldQueue: this Mailable is only ever
 * built and sent from inside App\Jobs\Timesheets\SendTimesheetEmailJob,
 * which is ITSELF the queued unit of work with its own delivery-status
 * tracking (queued/sent/failed on TimesheetEmailDelivery). If this class
 * also implemented ShouldQueue, calling ->send() on it would silently
 * re-queue instead of sending synchronously (Illuminate\Mail\PendingMail::
 * send() redirects to queue() for any ShouldQueue mailable) — the job would
 * mark the delivery `sent` immediately after merely re-queuing it, before
 * the mail transport had actually been contacted at all.
 */
class TimesheetSnapshotMail extends Mailable
{
    public function __construct(
        public readonly Timesheet $timesheet,
        public readonly Attachment $snapshot,
        public readonly string $emailSubject,
        public readonly string $employeeName,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.timesheet-snapshot',
            with: [
                'employeeName' => $this->employeeName,
                'periodStart' => $this->periodStart,
                'periodEnd' => $this->periodEnd,
                'version' => $this->timesheet->version,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromStorageDisk($this->snapshot->disk, $this->snapshot->storage_path)
                ->as("tabeli-{$this->timesheet->id}-v{$this->timesheet->version}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
