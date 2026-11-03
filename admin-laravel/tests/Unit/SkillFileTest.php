<?php

namespace Tests\Unit;

use App\Support\SkillFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SkillFileTest extends TestCase
{
    private const SKILL = "---\nname: application-status\ndescription: Use when the visitor asks about an application.\ntools: [get_application_status, list_applications]\n---\n\n# Steps\n\n1. Ask for the application number.\n";

    public function test_front_matter_and_body_are_read(): void
    {
        $skill = SkillFile::parse(self::SKILL);

        $this->assertSame('application-status', $skill['name']);
        $this->assertSame('Use when the visitor asks about an application.', $skill['description']);
        $this->assertSame(['get_application_status', 'list_applications'], $skill['tools']);
        $this->assertSame("# Steps\n\n1. Ask for the application number.", $skill['body']);
    }

    public function test_tools_may_be_a_plain_comma_list_or_absent(): void
    {
        $this->assertSame(['a', 'b'], SkillFile::parse("---\nname: x\ndescription: d\ntools: a, b\n---\nbody")['tools']);
        $this->assertSame([], SkillFile::parse("---\nname: x\ndescription: d\n---\nbody")['tools']);
    }

    public function test_windows_line_endings_and_a_bom_are_read(): void
    {
        $skill = SkillFile::parse("\u{FEFF}" . str_replace("\n", "\r\n", self::SKILL));

        $this->assertSame('application-status', $skill['name']);
        $this->assertStringNotContainsString("\r", $skill['body']);
    }

    public function test_a_models_draft_with_trailing_spaces_and_a_fence_is_read(): void
    {
        // What a drafting model actually wrote: Markdown hard breaks on every
        // line, quoted tool names, and sometimes a code fence around it all.
        $draft = "```markdown
---  
name: old-complaint  
description: Use when a complaint is old.  
"
            . "tools: [\"get_complaint\"]  
---  
1. Ask for the reference.
```
";

        $skill = SkillFile::parse($draft);

        $this->assertSame('old-complaint', $skill['name']);
        $this->assertSame('Use when a complaint is old.', $skill['description']);
        $this->assertSame(['get_complaint'], $skill['tools']);
        $this->assertSame('1. Ask for the reference.', $skill['body']);
    }

    public function test_quoted_values_lose_their_quotes(): void
    {
        $this->assertSame('Says "hi": politely',
            SkillFile::parse("---\nname: \"x\"\ndescription: 'Says \"hi\": politely'\n---\nbody")['description']);
    }

    public function test_a_missing_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        SkillFile::parse("# Just a document\n\nNo front matter.");
    }

    public function test_a_name_with_spaces_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkillFile::parse("---\nname: Hello World\ndescription: d\n---\nbody");
    }

    public function test_a_missing_description_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('description');

        SkillFile::parse("---\nname: x\n---\nbody");
    }

    public function test_render_round_trips(): void
    {
        $skill = SkillFile::parse(self::SKILL);

        $this->assertSame($skill, SkillFile::parse(SkillFile::render($skill)));
    }
}
