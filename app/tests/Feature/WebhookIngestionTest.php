<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('webhooks.partners.acme.secret', 'test-secret');
    Queue::fake();
});

function signedPost(array $payload, string $secret = 'test-secret')
{
    $body = json_encode($payload);

    return test()->call(
        'POST',
        '/api/webhooks/acme',
        [], [], [],
        [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ],
        $body
    );
}

it('accepts a valid delivery', function () {
    $response = signedPost(['id' => 'evt_001', 'type' => 'order.created']);

    $response->assertStatus(202)
        ->assertJson(['status' => 'accepted']);

    expect(WebhookEvent::count())->toBe(1);

    $event = WebhookEvent::first();
    expect($event->partner)->toBe('acme')
        ->and($event->external_id)->toBe('evt_001')
        ->and($event->status)->toBe('pending')
        ->and($event->payload)->toBeArray();

    Queue::assertPushed(ProcessWebhookEvent::class);
});

it('rejects an invalid signature and persists nothing', function () {
    $response = signedPost(['id' => 'evt_002'], secret: 'wrong-secret');

    $response->assertStatus(401);

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('treats a repeat delivery as duplicate and creates no second row', function () {
    signedPost(['id' => 'evt_003'])->assertStatus(202);

    $response = signedPost(['id' => 'evt_003']);

    $response->assertStatus(200)
        ->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::where('external_id', 'evt_003')->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookEvent::class, 1);
});

it('rejects a payload with no event id', function () {
    $response = signedPost(['type' => 'order.created']);

    $response->assertStatus(422);

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects an unknown partner', function () {
    $body = json_encode(['id' => 'evt_004']);

    $this->call('POST', '/api/webhooks/unknown', [], [], [], [
        'CONTENT_TYPE'           => 'application/json',
        'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $body, 'anything'),
    ], $body)->assertStatus(404);
});
