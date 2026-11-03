<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\Skill;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use ZipArchive;
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

    private const TEXT = "---\nname: refund-check\ndescription: Use when the visitor asks about a refund.\n---\n\nAsk for the order number.";

    private function member(string $role, string $system = 'sys_test'): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}-{$system}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($system, ['role' => $role]);

        return $user;
    }

    private function admin()
    {
        return $this->actingAs($this->member('system_admin'));
    }

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'skill') . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'skill.zip', 'application/zip', null, true);
    }

    private function skill(string $id = 'skl_1', string $system = 'sys_test', string $name = 'refund-check'): Skill
    {
        return Skill::create(['id' => $id, 'system_id' => $system, 'name' => $name, 'description' => 'd',
            'body' => 'b', 'tools' => [], 'origin' => 'typed']);
    }

    public function test_the_page_lists_the_workspaces_skills_and_the_built_ins(): void
    {
        $this->skill();
        $this->skill('skl_x', 'sys_other', 'theirs-only');

        $this->actingAs($this->member('viewer'))
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee('refund-check')
            ->assertSee('human-handover')
            ->assertDontSee('theirs-only');
    }

    public function test_editors_cannot_change_skills(): void
    {
        $skill = $this->skill();
        $editor = $this->actingAs($this->member('editor'));

        $editor->postJson(route('skills.store'), ['system_id' => 'sys_test', 'content' => self::TEXT])->assertForbidden();
        $editor->putJson(route('skills.update', $skill->id), ['content' => self::TEXT])->assertForbidden();
        $editor->deleteJson(route('skills.destroy', $skill->id))->assertForbidden();
        $editor->postJson(route('skills.copy', 'skl_builtin_ticket'), ['system_id' => 'sys_test'])->assertForbidden();
    }

    public function test_a_typed_skill_is_saved(): void
    {
        $this->admin()->postJson(route('skills.store'), ['system_id' => 'sys_test', 'content' => self::TEXT])
            ->assertOk();

        $skill = Skill::where('system_id', 'sys_test')->sole();
        $this->assertSame(['refund-check', 'typed', 'Ask for the order number.'], [$skill->name, $skill->origin, $skill->body]);
    }

    public function test_a_bad_skill_file_is_refused_with_its_reason(): void
    {
        $this->admin()->postJson(route('skills.store'), ['system_id' => 'sys_test', 'content' => 'no front matter'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_a_markdown_file_is_uploaded(): void
    {
        $file = UploadedFile::fake()->createWithContent('SKILL.md', self::TEXT);

        $this->admin()->post(route('skills.upload'), ['system_id' => 'sys_test', 'file' => $file],
            ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('upload', Skill::where('system_id', 'sys_test')->sole()->origin);
    }

    public function test_a_zip_with_one_skill_folder_is_uploaded_and_other_files_are_named(): void
    {
        $file = $this->zip(['refund-check/SKILL.md' => self::TEXT, 'refund-check/notes.txt' => 'x']);

        $this->admin()->post(route('skills.upload'), ['system_id' => 'sys_test', 'file' => $file],
            ['Accept' => 'application/json'])
            ->assertOk()
            ->assertSee('notes.txt');

        $this->assertSame('refund-check', Skill::where('system_id', 'sys_test')->sole()->name);
    }

    public function test_a_zip_reaching_outside_its_folder_is_refused(): void
    {
        $file = $this->zip(['../SKILL.md' => self::TEXT]);

        $this->admin()->post(route('skills.upload'), ['system_id' => 'sys_test', 'file' => $file],
            ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, Skill::where('system_id', 'sys_test')->count());
    }

    public function test_a_zip_with_two_skills_is_refused(): void
    {
        $file = $this->zip(['a/SKILL.md' => self::TEXT, 'b/SKILL.md' => self::TEXT]);

        $this->admin()->post(route('skills.upload'), ['system_id' => 'sys_test', 'file' => $file],
            ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_built_ins_cannot_be_changed_or_deleted(): void
    {
        $super = User::create(['name' => 'S', 'email' => 's@example.test', 'password' => 'password', 'global_role' => 'super_admin']);

        $this->actingAs($super)->putJson(route('skills.update', 'skl_builtin_ticket'), ['content' => self::TEXT])->assertForbidden();
        $this->actingAs($super)->deleteJson(route('skills.destroy', 'skl_builtin_ticket'))->assertForbidden();
    }

    public function test_a_built_in_is_copied_into_the_workspace(): void
    {
        $admin = $this->admin();
        $admin->postJson(route('skills.copy', 'skl_builtin_ticket'), ['system_id' => 'sys_test'])->assertOk();
        $admin->postJson(route('skills.copy', 'skl_builtin_ticket'), ['system_id' => 'sys_test'])->assertOk();

        $names = Skill::where('system_id', 'sys_test')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['create-ticket', 'create-ticket-2'], $names);
        $this->assertSame(['create_ticket'], Skill::where('system_id', 'sys_test')->first()->tools);
    }

    public function test_a_skill_name_is_unique_in_its_workspace(): void
    {
        $this->skill();

        $this->admin()->postJson(route('skills.store'), ['system_id' => 'sys_test', 'content' => self::TEXT])
            ->assertStatus(422);
    }

    public function test_another_workspaces_skill_is_out_of_reach(): void
    {
        $theirs = $this->skill('skl_x', 'sys_other');

        $this->admin()->putJson(route('skills.update', $theirs->id), ['content' => self::TEXT])->assertForbidden();
    }

    public function test_a_skill_in_use_is_not_deleted(): void
    {
        $skill = $this->skill();
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper'])->skills()->attach($skill->id);

        $this->admin()->deleteJson(route('skills.destroy', $skill->id))->assertStatus(409)->assertSee('Helper');
    }

    public function test_a_skill_downloads_as_its_file(): void
    {
        $skill = $this->skill();

        $this->actingAs($this->member('viewer'))->get(route('skills.download', $skill->id))
            ->assertOk()
            ->assertSee('name: refund-check', false);
    }

    public function test_a_draft_comes_from_the_workspaces_own_model_and_is_not_saved(): void
    {
        AiProvider::create(['id' => 'aip_1', 'system_id' => 'sys_test', 'name' => 'Ours', 'base_url' => 'http://llm/v1']);
        Http::fake(['*/api/v1/skills/draft' => Http::response(['success' => true, 'skill' => self::TEXT])]);

        $this->admin()->postJson(route('skills.draft'), ['system_id' => 'sys_test', 'provider_id' => 'aip_1',
            'model' => 'm', 'description' => 'Check refunds'])
            ->assertOk()
            ->assertJson(['success' => true, 'skill' => self::TEXT]);

        Http::assertSent(fn ($request) => $request['provider_id'] === 'aip_1' && $request['description'] === 'Check refunds');
        $this->assertSame(0, Skill::where('system_id', 'sys_test')->count());
    }

    public function test_a_draft_cannot_borrow_another_workspaces_provider(): void
    {
        AiProvider::create(['id' => 'aip_x', 'system_id' => 'sys_other', 'name' => 'Theirs', 'base_url' => 'http://llm/v1']);
        Http::fake();

        $this->admin()->postJson(route('skills.draft'), ['system_id' => 'sys_test', 'provider_id' => 'aip_x',
            'model' => 'm', 'description' => 'Check refunds'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_page_offers_generate_when_the_workspace_has_a_model(): void
    {
        AiProvider::create(['id' => 'aip_1', 'system_id' => 'sys_test', 'name' => 'Ours', 'base_url' => 'http://llm/v1']);

        $this->actingAs($this->member('system_admin'))
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('skills.index'))
            ->assertOk()
            ->assertSee('id="skillGenerate"', false);
    }
}
