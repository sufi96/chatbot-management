<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A workspace's skills: typed, uploaded, generated, or copied from a built-in. */
class SkillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
    }

    public function test_three_built_ins_ship_with_c4(): void
    {
        $builtIns = Skill::whereNull('system_id')->orderBy('name')->get();

        $this->assertSame(['application-status', 'create-ticket', 'human-handover'], $builtIns->pluck('name')->all());
        $this->assertTrue($builtIns->every(fn (Skill $s) => $s->origin === 'builtin' && $s->body !== ''));
        $this->assertSame(['create_ticket'], $builtIns->firstWhere('name', 'create-ticket')->tools);
    }
}
