<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

    $partner = $request->route('partner');
    $secret = config("webhooks.partners.{$partner}.secret");

    if (! $secret) {
        return response()->json(['error' => 'unknown partner'], 404);
    }

    //Verifying against $request->getContent() because re-encoded JSON produces intermittent failures.
    $provided = $request->header('X-Relay-Signature', '');

    $expected = hash_hmac('sha256', $request->getContent(), $secret);

    // hash_equals rather than ===. String comparison short-circuits on the
    // first differing byte, leaking the signature to anyone timing responses.
    if (! hash_equals($expected, $provided)) {
        Log::warning('webhook signature rejected', ['partner' => $partner]);
        return response()->json(['error' => 'invalid signature'], 401);
    }

    return $next($request);
    }
}
