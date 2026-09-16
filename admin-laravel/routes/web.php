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
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AiProviderController;
use App\Http\Controllers\DbConnectionController;
use App\Http\Controllers\DbPlaygroundController;
use App\Http\Controllers\DbSchemaController;

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
    // Distinct path, so it cannot be mistaken for /knowledge/{id}
    Route::get('/knowledge-playground', [KnowledgeBaseController::class, 'playground'])->name('kb.playground');
    Route::post('/knowledge-playground', [KnowledgeBaseController::class, 'runPlayground'])->name('kb.playground.run');
    Route::get('/knowledge/{id}', [KnowledgeBaseController::class, 'show'])->name('kb.show');
    Route::delete('/knowledge/{id}', [KnowledgeBaseController::class, 'destroy'])->name('kb.destroy');
    Route::post('/knowledge/{id}/sources', [KnowledgeBaseController::class, 'storeSource'])->name('kb.sources.store');
    Route::post('/knowledge/{id}/upload', [KnowledgeBaseController::class, 'uploadSource'])->name('kb.sources.upload');
    Route::get('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'showSource'])->name('kb.sources.show');
    Route::put('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'updateSource'])->name('kb.sources.update');
    Route::get('/knowledge/sources/{sourceId}/download', [KnowledgeBaseController::class, 'downloadSource'])->name('kb.sources.download');
    Route::post('/knowledge/sources/{sourceId}/reindex', [KnowledgeBaseController::class, 'reindexSource'])->name('kb.sources.reindex');
    Route::delete('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'destroySource'])->name('kb.sources.destroy');

    // AI providers. Managed from inside the bot form, so every action
    // answers JSON and nothing here renders a page of its own.
    Route::post('/providers', [AiProviderController::class, 'store'])->name('providers.store');
    Route::put('/providers/{id}', [AiProviderController::class, 'update'])->name('providers.update');
    Route::delete('/providers/{id}', [AiProviderController::class, 'destroy'])->name('providers.destroy');

    // Database connections
    // Distinct path, so it cannot be mistaken for /databases/{id}
    Route::get('/database-playground', [DbPlaygroundController::class, 'show'])->name('databases.playground');
    Route::post('/database-playground', [DbPlaygroundController::class, 'run'])->name('databases.playground.run');
    Route::get('/databases', [DbConnectionController::class, 'index'])->name('databases.index');
    Route::post('/databases', [DbConnectionController::class, 'store'])->name('databases.store');
    // Ahead of the {id} routes, and on a path they cannot match, so testing an
    // unsaved form is never read as testing a connection called "test".
    Route::post('/databases/test-draft', [DbConnectionController::class, 'testDraft'])->name('databases.test-draft');
    Route::put('/databases/{id}', [DbConnectionController::class, 'update'])->name('databases.update');
    Route::delete('/databases/{id}', [DbConnectionController::class, 'destroy'])->name('databases.destroy');
    Route::post('/databases/{id}/test', [DbConnectionController::class, 'test'])->name('databases.test');
    Route::post('/databases/{id}/toggle', [DbConnectionController::class, 'toggle'])->name('databases.toggle');

    // Schema editor. Table and column ids are integers, so they get their own
    // path prefixes rather than nesting under /databases/{id}, where a numeric
    // id could be read as a connection.
    Route::get('/databases/{id}/schema', [DbSchemaController::class, 'show'])->name('databases.schema');
    Route::post('/databases/{id}/introspect', [DbSchemaController::class, 'introspect'])->name('databases.introspect');
    Route::post('/databases/{id}/tables', [DbSchemaController::class, 'storeTable'])->name('databases.tables.store');
    Route::post('/databases/{id}/tables/readable', [DbSchemaController::class, 'bulkSetReadable'])->name('databases.tables.readable');
    Route::put('/database-tables/{tableId}', [DbSchemaController::class, 'updateTable'])->name('databases.tables.update');
    Route::delete('/database-tables/{tableId}', [DbSchemaController::class, 'destroyTable'])->name('databases.tables.destroy');
    Route::post('/database-tables/{tableId}/columns', [DbSchemaController::class, 'storeColumn'])->name('databases.columns.store');
    Route::put('/database-columns/{columnId}', [DbSchemaController::class, 'updateColumn'])->name('databases.columns.update');
    Route::delete('/database-columns/{columnId}', [DbSchemaController::class, 'destroyColumn'])->name('databases.columns.destroy');

    // Conversation Logs & Transcripts
    Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{id}/transcript', [LogController::class, 'transcript'])->name('logs.transcript');

    // Global User Management (Super Admin)
    Route::middleware('super_admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

        // Platform-wide settings: embedding, vector store, chunking
        Route::get('/admin/settings', [AdminSettingsController::class, 'edit'])->name('admin.settings');
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update');
        Route::post('/admin/settings/test', [AdminSettingsController::class, 'test'])->name('admin.settings.test');
        Route::post('/admin/settings/models', [AdminSettingsController::class, 'models'])->name('admin.settings.models');
        Route::post('/admin/settings/reindex', [AdminSettingsController::class, 'reindex'])->name('admin.settings.reindex');
    });
});
