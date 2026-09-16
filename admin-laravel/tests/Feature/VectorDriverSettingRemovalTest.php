<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VectorDriverSettingRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        // The migration class is anonymous, so load the file and call it directly.
        $migration = require database_path(
            'migrations/2026_09_15_000006_remove_vector_driver_setting.php');
        $migration->up();
    }

    public function test_the_stored_vector_driver_row_is_removed(): void
    {
        AppSetting::put('vector_driver', 'pgvector');

        $this->runMigration();

        $this->assertFalse(DB::table('app_settings')->where('key', 'vector_driver')->exists());
    }

    public function test_other_settings_are_left_alone(): void
    {
        AppSetting::put('vector_driver', 'sqlite');
        AppSetting::put('embedding_model', 'nomic-embed-text');

        $this->runMigration();

        $this->assertSame('nomic-embed-text', AppSetting::get('embedding_model'));
    }

    public function test_an_install_that_never_stored_it_migrates_cleanly(): void
    {
        $this->runMigration();

        $this->assertFalse(DB::table('app_settings')->where('key', 'vector_driver')->exists());
    }
}
