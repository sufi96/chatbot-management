<?php

use App\Http\Controllers\InternalQueryController;
use Illuminate\Support\Facades\Route;

// The engine calling in. No session, no CSRF, no cookies: this is a
// service-to-service call carrying its own shared secret, and putting it in
// its own file is cleaner than excluding it from the web group's middleware.
Route::middleware('engine.token')->post('/internal/db/query',
    [InternalQueryController::class, 'query'])->name('internal.db.query');
