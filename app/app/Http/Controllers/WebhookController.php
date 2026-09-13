<?php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\WebhookEvent;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class WebhookController extends Controller
{

/**
 * Accept a partner webhook delivery.
 *
 * Verifies nothing beyond the event ID; the payload is stored raw and
 * interpreted asynchronously. Returns 202 on acceptance, 200 on duplicate.
 */
public function store(Request $request, string $partner): JsonResponse
{
    $payload = $request->json()->all();
    $externalId = $payload['id'] ?? null;

    if (! $externalId) {
        return response()->json(['error' => 'missing event id'], 422);
    }

    try {
        $event = WebhookEvent::create([
            'partner'     => $partner,
            'external_id' => $externalId,
            'event_type'  => $payload['type'] ?? null,
            'payload'     => $payload,
            'signature'   => $request->header('X-Relay-Signature'),
        ]);
    } catch (UniqueConstraintViolationException) {
        // Duplicate delivery. The sender already succeeded; tell it so.
        return response()->json(['status' => 'duplicate'], 200);
    }

    ProcessWebhookEvent::dispatch($event->id);

    return response()->json(['status' => 'accepted', 'id' => $event->id], 202);
}

}
