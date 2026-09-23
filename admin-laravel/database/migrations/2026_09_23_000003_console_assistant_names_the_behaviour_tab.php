<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bot settings tab once called Brain is now Behaviour, and the console
 * assistant's prompt tells super admins where things are. Only the sentence
 * as it was written is replaced, so a prompt someone rewrote is left alone.
 */
return new class extends Migration
{
    private const OLD = 'The Brain tab holds the prompt, knowledge, database and web sources, intent and guard.';

    private const NEW = 'The Behaviour tab holds the prompt, the model and endpoint, answer sources, web search, guard, generation settings such as temperature and max tokens, and the Brain: knowledge base and databases.';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        $prompt = DB::table('bot_profiles')->where('id', 'bot_console_assistant')->value('system_prompt');

        if ($prompt !== null && str_contains($prompt, $from)) {
            DB::table('bot_profiles')->where('id', 'bot_console_assistant')
                ->update(['system_prompt' => str_replace($from, $to, $prompt)]);
        }
    }
};
