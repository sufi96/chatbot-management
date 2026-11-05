<?php

use App\Http\Controllers\InternalAssistantController;
use App\Http\Controllers\InternalQueryController;
use Illuminate\Support\Facades\Route;

// The engine calling in. No session, no CSRF, no cookies: this is a
// service-to-service call carrying its own shared secret, and putting it in
// its own file is cleaner than excluding it from the web group's middleware.
Route::middleware('engine.token')->post('/internal/db/query',
    [InternalQueryController::class, 'query'])->name('internal.db.query');

// The console assistant's tools, run as the person whose console token is sent.
Route::middleware('engine.token')->post('/internal/assistant/tools',
    [InternalAssistantController::class, 'tools'])->name('internal.assistant.tools');
Route::middleware('engine.token')->post('/internal/assistant/call',
    [InternalAssistantController::class, 'call'])->name('internal.assistant.call');
