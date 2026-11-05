<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('assistant:coverage', function () {
    \App\Assistant\Coverage::write();
    $this->info('docs/admin-assistant.md updated.');
})->purpose("Rewrite the console assistant's coverage list from App\\Assistant\\Coverage");
