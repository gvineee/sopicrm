<?php

namespace App\Mail;

use App\Domain\Timesheets\Models\TimesheetEmailDeliveryItem;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * TIMESHEET-EMAIL-02: the `bundled`-mode sibling of App\Mail\
 * TimesheetSnapshotMail — one email carrying MULTIPLE already-generated,
 * immutable PDF snapshots (one per selected timesheet) to one explicitly
 * chosen recipient (e.g. an accountant). Never regenerates any snapshot;
 * each attached file is byte-for-byte what
 * App\Domain\Timesheets\Actions\CreateTimesheetEmailBatchAction stored at
 * batch-creation time via the same App\Domain\Timesheets\Support\
 * TimesheetSnapshotResolver TIMESHEET-EMAIL-01 uses.
 *
 * Deliberately does NOT implement ShouldQueue — same reasoning as
 * TimesheetSnapshotMail's own docblock: this Mailable is only ever built
 * and sent from inside App\Jobs\Timesheets\SendBundledTimesheetEmailJob,
 * which is itself the queued unit of work.
 */
class TimesheetBundleSnapshotMail extends Mailable
{
    /**
     * @param  Collection<int, TimesheetEmailDeliveryItem>  $items
     */
    public function __construct(
        public readonly Collection $items,
        public readonly string $emailSubject,
        public readonly string $recipientLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.timesheet-bundle-snapshot',
            with: [
                'recipientLabel' => $this->recipientLabel,
                'count' => $this->items->count(),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return $this->items->map(function (TimesheetEmailDeliveryItem $item) {
            $attachment = $item->attachment;

            return Attachment::fromStorageDisk($attachment->disk, $attachment->storage_path)
                ->as("tabeli-{$item->timesheet_id}-v{$item->timesheet_version_at_send}.pdf")
                ->withMime('application/pdf');
        })->all();
    }
}
