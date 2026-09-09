<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\BotProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\BotBrainController;

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Authenticated Application Routes with System Context RBAC
Route::middleware(['auth', 'system.access'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // System Switcher
    Route::post('/systems/switch', [SystemController::class, 'switch'])->name('systems.switch');

    // Systems Management
    Route::get('/systems', [SystemController::class, 'index'])->name('systems.index');
    Route::post('/systems', [SystemController::class, 'store'])->name('systems.store');
    Route::put('/systems/{id}', [SystemController::class, 'update'])->name('systems.update');
    Route::delete('/systems/{id}', [SystemController::class, 'destroy'])->name('systems.destroy');

    // System User Assignments (Super Admin)
    Route::get('/systems/{id}/users', [SystemController::class, 'users'])->name('systems.users');
    Route::post('/systems/{id}/users', [SystemController::class, 'assignUser'])->name('systems.users.assign');
    Route::put('/systems/{id}/users/{userId}', [SystemController::class, 'updateUserRole'])->name('systems.users.update');
    Route::delete('/systems/{id}/users/{userId}', [SystemController::class, 'removeUser'])->name('systems.users.remove');

    // Bot Profiles
    Route::get('/bots', [BotProfileController::class, 'index'])->name('bots.index');
    Route::get('/bots/create', [BotProfileController::class, 'create'])->name('bots.create');
    Route::post('/bots', [BotProfileController::class, 'store'])->name('bots.store');
    Route::get('/bots/{id}/edit', [BotProfileController::class, 'edit'])->name('bots.edit');
    Route::put('/bots/{id}', [BotProfileController::class, 'update'])->name('bots.update');
    Route::delete('/bots/{id}', [BotProfileController::class, 'destroy'])->name('bots.destroy');
    Route::get('/bots/{id}/brain', [BotBrainController::class, 'edit'])->name('bots.brain');
    Route::put('/bots/{id}/brain', [BotBrainController::class, 'update'])->name('bots.brain.update');
    Route::get('/bots/{id}/embed', [BotProfileController::class, 'embed'])->name('bots.embed');

    // Knowledge base
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('kb.index');
    Route::post('/knowledge', [KnowledgeBaseController::class, 'store'])->name('kb.store');
    Route::get('/knowledge/{id}', [KnowledgeBaseController::class, 'show'])->name('kb.show');
    Route::delete('/knowledge/{id}', [KnowledgeBaseController::class, 'destroy'])->name('kb.destroy');
    Route::post('/knowledge/{id}/sources', [KnowledgeBaseController::class, 'storeSource'])->name('kb.sources.store');
    Route::post('/knowledge/sources/{sourceId}/reindex', [KnowledgeBaseController::class, 'reindexSource'])->name('kb.sources.reindex');
    Route::delete('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'destroySource'])->name('kb.sources.destroy');

    // Conversation Logs & Transcripts
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{id}/transcript', [LogController::class, 'transcript'])->name('logs.transcript');

    // Global User Management (Super Admin)
    Route::middleware('super_admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
