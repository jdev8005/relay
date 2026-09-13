<?php
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{partner}', [WebhookController::class, 'store'])
    ->middleware('webhook.signature');
