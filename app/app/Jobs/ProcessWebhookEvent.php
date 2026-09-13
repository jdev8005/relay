<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 30;

    // The event ID, not the model. A serialized model carries a snapshot
    // of the row as it existed at dispatch; by the time the worker runs it
    // may be stale, or the row may be gone. Loading fresh is always correct.
    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::find($this->eventId);

        if (! $event) {
            Log::warning('webhook event vanished before processing', [
                'event_id' => $this->eventId,
            ]);
            return;
        }

        // A retry can land on a row a previous attempt already completed.
        if ($event->status === 'processed') {
            return;
        }

        $event->update([
            'status'   => 'processing',
            'attempts' => $event->attempts + 1,
        ]);

        // TODO(week-6): real per-partner processing. For now the pipeline
        // itself is what's under test, not what it does with the payload.

        $event->update([
            'status'       => 'processed',
            'processed_at' => now(),
            'last_error'   => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        WebhookEvent::where('id', $this->eventId)->update([
            'status'     => 'failed',
            'last_error' => $e?->getMessage(),
        ]);

        Log::error('webhook processing failed permanently', [
            'event_id' => $this->eventId,
            'error'    => $e?->getMessage(),
        ]);
    }
}
