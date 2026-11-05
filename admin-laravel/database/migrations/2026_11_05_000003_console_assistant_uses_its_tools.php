<?php

use App\Support\SourceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The console assistant gets tools for the console itself (app/Assistant):
 * its tools source on and asked first, and a paragraph in its prompt saying
 * what they are for. The paragraph is added, not swapped in, so a prompt a
 * super admin rewrote keeps their words.
 */
return new class extends Migration
{
    private const ID = 'bot_console_assistant';

    private const PARAGRAPH = 'You have tools for this console. Use them to look things up (bots, conversations, failed tool calls, usage, knowledge, connectors, skills, databases, people) instead of guessing, and to make the changes the person asks for. When a tool returned results, answer from them: give the actual names, numbers and details, not directions to a page. When a tool says "Not done", tell the person why in plain words. A change only happens after the person presses Confirm, and their own role decides what they may do. Never ask for or repeat a password or key.';

    public function up(): void
    {
        $bot = DB::table('bot_profiles')->where('id', self::ID)->first();
        if (!$bot) {
            return;
        }

        $order = array_values(array_diff(explode(',', SourceOrder::normalise($bot->source_order ?? null)), ['tools']));
        $prompt = (string) $bot->system_prompt;

        DB::table('bot_profiles')->where('id', self::ID)->update([
            'agentic_enabled' => true,
            'agentic_max_calls' => max(4, (int) ($bot->agentic_max_calls ?? 0)),
            'source_order' => implode(',', array_merge(['tools'], $order)),
            'system_prompt' => str_contains($prompt, self::PARAGRAPH) ? $prompt : trim($prompt . "\n\n" . self::PARAGRAPH),
        ]);
    }

    public function down(): void
    {
        $prompt = (string) DB::table('bot_profiles')->where('id', self::ID)->value('system_prompt');

        DB::table('bot_profiles')->where('id', self::ID)->update([
            'agentic_enabled' => false,
            'source_order' => SourceOrder::DEFAULT,
            'system_prompt' => trim(str_replace(self::PARAGRAPH, '', $prompt)),
        ]);
    }
};
