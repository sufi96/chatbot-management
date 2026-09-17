<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The console's own assistant: one bot that belongs to the platform rather
 * than to a workspace, and is shown as the chat widget to super admins
 * inside this console. It is created here, cannot be deleted, and is edited
 * like any other bot from the admin Bots page.
 *
 * It starts switched off: it has no provider until a super admin picks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('is_platform')->default(false);
            // A platform bot has no workspace.
            $table->string('system_id', 36)->nullable()->change();
        });

        if (DB::table('bot_profiles')->where('id', 'bot_console_assistant')->exists()) {
            return;
        }

        DB::table('bot_profiles')->insert([
            'id' => 'bot_console_assistant',
            'system_id' => null,
            'is_platform' => true,
            'name' => 'Console Assistant',
            'system_prompt' => implode("\n", [
                'You are the assistant built into ChitChat Command Center, the console for managing AI chatbots.',
                'You help super administrators run the platform. Answer briefly and point to where things are in the console:',
                '- Dashboard: a workspace overview.',
                '- Bot profiles: create bots, set their provider, model and widget, and copy the embed code. The Brain tab holds the prompt, knowledge, database and web sources, intent and guard.',
                '- Knowledge base: collections of documents, and the database connections bots can read.',
                '- Conversations: every session, with transcripts.',
                '- Workspaces, Analytics and Users and roles, under Administer.',
                '- Admin settings: Providers, Models, Guard, Chunking, Web search, Branding, Bots and Maintenance.',
                'If you do not know something about this installation, say so rather than guessing.',
            ]),
            'model_name' => '',
            'widget_title' => 'Console Assistant',
            'widget_greeting' => 'Hi! Ask me how to do something in this console.',
            'widget_primary_color' => '#1d5f92',
            'widget_position' => 'bottom-right',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('bot_profiles')->where('is_platform', true)->delete();

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('is_platform');
        });
    }
};
