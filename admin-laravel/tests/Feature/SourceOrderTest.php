<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_well_formed_order_is_kept_as_it_is(): void
    {
        $this->assertSame('database,documents,web',
            SourceOrder::normalise('database,documents,web'));
    }

    public function test_a_missing_source_is_appended_in_default_order(): void
    {
        $this->assertSame('web,documents,database', SourceOrder::normalise('web'));
    }

    public function test_an_unknown_token_is_dropped(): void
    {
        $this->assertSame('web,documents,database',
            SourceOrder::normalise('web,telepathy'));
    }

    public function test_a_duplicate_keeps_its_first_position(): void
    {
        $this->assertSame('web,documents,database',
            SourceOrder::normalise('web,web,web'));
    }

    public function test_an_empty_order_is_the_default(): void
    {
        $this->assertSame(SourceOrder::DEFAULT, SourceOrder::normalise(''));
        $this->assertSame(SourceOrder::DEFAULT, SourceOrder::normalise(null));
    }

    public function test_spacing_and_case_do_not_matter(): void
    {
        $this->assertSame('database,web,documents',
            SourceOrder::normalise(' Database , WEB '));
    }

    public function test_the_list_form_is_the_normalised_order(): void
    {
        $this->assertSame(['web', 'documents', 'database'], SourceOrder::toList('web'));
    }

    public function test_a_new_bot_consults_its_documents_first(): void
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $bot = BotProfile::create([
            'id' => 'bot_order', 'system_id' => 'sys_test', 'name' => 'Orderly',
        ]);

        $this->assertSame(SourceOrder::DEFAULT, $bot->fresh()->source_order);
    }
}
