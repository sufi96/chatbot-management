<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skills: packaged know-how for one task, in the Agent Skills SKILL.md
     * format. A workspace types, uploads or generates its own; three built-ins
     * (system_id null) ship with C⁴ and are copied into a workspace to be used.
     *
     * tools names the connector operations a skill needs. An agentic bot is
     * offered a skill only when it has every one of them.
     */
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36)->nullable()->index();
            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->string('name', 64);
            $table->text('description');
            $table->longText('body');
            $table->json('tools')->nullable();
            // upload, typed, generated, builtin
            $table->string('origin', 20);
            $table->timestamps();
            $table->unique(['system_id', 'name']);
        });

        Schema::create('bot_skills', function (Blueprint $table) {
            $table->string('bot_id', 36);
            $table->string('skill_id', 36);
            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('skill_id')->references('id')->on('skills')->cascadeOnDelete();
            $table->primary(['bot_id', 'skill_id']);
        });

        $now = now();
        foreach (self::BUILT_INS as $id => [$name, $description, $tools, $body]) {
            DB::table('skills')->insert([
                'id' => $id, 'system_id' => null, 'name' => $name, 'description' => $description,
                'body' => $body, 'tools' => json_encode($tools), 'origin' => 'builtin',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_skills');
        Schema::dropIfExists('skills');
    }

    private const BUILT_INS = [
        'skl_builtin_handover' => [
            'human-handover',
            'Use when the visitor asks for a person, or the bot cannot help after two tries.',
            [],
            "# Hand over to a person\n\n"
            . "1. Say plainly that a member of staff can help with this.\n"
            . "2. Ask for the best way to reach them (email or phone) and a one-line summary of what they need.\n"
            . "3. Repeat the details back and ask them to confirm they are right.\n"
            . "4. Tell them when staff usually reply, if the knowledge base says so. Never promise a time it does not give.",
        ],
        'skl_builtin_ticket' => [
            'create-ticket',
            'Use when the visitor reports a problem that needs follow-up by staff.',
            ['create_ticket'],
            "# Open a ticket\n\n"
            . "1. Ask what went wrong, when it started, and what they were trying to do.\n"
            . "2. Ask for a contact detail if the ticket needs one.\n"
            . "3. Summarise the ticket in one or two sentences and call create_ticket with it.\n"
            . "4. The visitor confirms before it is sent. Once it is, give them the ticket number if the result has one.",
        ],
        'skl_builtin_status' => [
            'application-status',
            'Use when the visitor asks about the status of an application they submitted.',
            ['get_application_status'],
            "# Check an application\n\n"
            . "1. Ask for the application or reference number. Never guess one.\n"
            . "2. Call get_application_status with it.\n"
            . "3. Say the status in plain words and what usually happens next.\n"
            . "4. If nothing is found, ask them to check the number once, then offer a person.",
        ],
    ];
};
