<?php

namespace App\Services\Schema;

use App\Models\BotProfile;
use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use Illuminate\Support\Facades\DB;

/**
 * This console's own database, as a connection the console assistant reads.
 *
 * It stores no host or password: it opens whatever database the application
 * itself is configured to use (see ProbeConnection), marked by the console
 * option. It belongs to no workspace, so only a super admin sees it, and it
 * opens read-only on top of the usual SELECT-only checks.
 *
 * Some tables hold secrets and are never readable, whatever is ticked on the
 * schema page: account passwords, provider keys, connection passwords and the
 * platform settings. DbQueryRunner holds to that list too.
 */
final class ConsoleDatabase
{
    public const ID = 'dbc_console';

    /** Never readable, not even when ticked by hand. */
    public const NEVER = [
        'users', 'password_reset_tokens', 'personal_access_tokens', 'sessions',
        'app_settings', 'ai_providers', 'db_connections',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
    ];

    /**
     * Readable from the start, each with what it holds, so the SQL model can
     * write a correct query without guessing from names.
     */
    public const READABLE = [
        'systems' => ['Workspaces. Each holds bot profiles, knowledge and members.', [
            'id' => 'Workspace id.',
            'name' => 'Workspace name.',
            'description' => 'What the workspace is for.',
            'allowed_origins' => 'Comma-separated sites allowed to embed its bots; * means any.',
            'created_at' => 'When the workspace was created, UTC.',
        ]],
        'system_user' => ['Who belongs to which workspace, and their role there.', [
            'system_id' => 'The workspace, systems.id.',
            'user_id' => 'The member. Their name and email are not readable.',
            'role' => 'system_admin, editor or viewer.',
        ]],
        'bot_profiles' => ['Chatbots. Each belongs to a workspace, except the console assistant.', [
            'id' => 'Bot id, used in its embed code.',
            'system_id' => 'Its workspace, systems.id. Null for the console assistant.',
            'name' => 'Bot name.',
            'model_name' => 'The model that writes its answers.',
            'is_active' => 'True when it is switched on and answering.',
            'is_platform' => 'True only for the console assistant.',
            'retrieval_enabled' => 'True when it answers from its knowledge base.',
            'db_query_enabled' => 'True when it answers from a connected database.',
            'web_search_enabled' => 'True when it searches the web.',
            'guard_enabled' => 'True when the safety guard checks its messages.',
            'deleted_at' => 'Set when the bot was deleted from its workspace; null otherwise. Leave deleted bots out unless asked.',
            'created_at' => 'When the bot was created, UTC.',
        ]],
        'chat_conversations' => ['Visitor sessions with a bot. One row per session.', [
            'id' => 'Conversation id.',
            'bot_id' => 'The bot, bot_profiles.id.',
            'session_id' => "The widget's session id.",
            'origin' => 'The site the visitor chatted from; empty for the preview sandbox.',
            'created_at' => 'When the session started, UTC.',
        ]],
        'chat_messages' => ['Every message in a conversation, from the visitor or the bot.', [
            'conversation_id' => 'The conversation, chat_conversations.id.',
            'sender' => 'user for the visitor, assistant for the bot.',
            'content' => 'The message text.',
            'tokens_used' => 'Tokens the answer cost, prompt and reply together.',
            'tokens_in' => 'Prompt tokens of an answer.',
            'tokens_out' => 'Reply tokens of an answer.',
            'intent' => 'On a visitor message: facts for a question, chat for small talk.',
            'guard_flag' => 'Set when the guard refused a visitor message or flagged an answer; the category.',
            'source_kind' => 'On an answer: documents, database, web, none (searched, found nothing), model (nothing searched) or refused.',
            'first_token_ms' => 'Milliseconds until the answer started.',
            'response_ms' => 'Milliseconds until the answer finished.',
            'created_at' => 'When the message was sent, UTC.',
        ]],
        'kb_collections' => ['Knowledge base collections. Each belongs to a workspace.', [
            'id' => 'Collection id.',
            'system_id' => 'Its workspace, systems.id.',
            'name' => 'Collection name.',
        ]],
        'kb_sources' => ['Documents in a knowledge base collection.', [
            'collection_id' => 'The collection, kb_collections.id.',
            'type' => 'text, file or qa.',
            'title' => 'Document title.',
            'status' => 'pending, processing, ready or error.',
            'created_at' => 'When it was added, UTC.',
        ]],
        'bot_kb_collection' => ['Which knowledge base collections each bot answers from.', [
            'bot_id' => 'The bot, bot_profiles.id.',
            'collection_id' => 'The collection, kb_collections.id.',
        ]],
        'bot_db_connection' => ['Which database connections each bot reads.', [
            'bot_id' => 'The bot, bot_profiles.id.',
            'connection_id' => 'The connection id.',
        ]],
    ];

    /**
     * Creates or refreshes the connection, reads the schema, opens the
     * readable tables and links it to the console assistant. Safe to run
     * again: descriptions somebody has written are kept.
     */
    public static function ensure(): DbConnection
    {
        $default = config('database.default');
        $config = config("database.connections.{$default}");

        // Refreshed every time, so the connection follows the application
        // from SQLite to PostgreSQL. No credentials are copied.
        $connection = DbConnection::firstOrNew(['id' => self::ID]);
        $connection->forceFill([
            'system_id' => null,
            'name' => 'Console database',
            'driver' => $config['driver'],
            'host' => null,
            'port' => null,
            'database' => (string) ($config['database'] ?? ''),
            'username' => null,
            'password' => null,
            'options' => ['console' => true],
            'is_enabled' => true,
        ])->save();

        SchemaSync::apply($connection, SchemaIntrospector::discover($connection));
        $connection->update(['status' => 'ok', 'error_message' => null]);

        foreach (DbTable::where('connection_id', $connection->id)->get() as $table) {
            if (in_array($table->table_name, self::NEVER, true)) {
                $table->update(['is_enabled' => false]);
                continue;
            }

            [$description, $columns] = self::READABLE[$table->table_name] ?? [null, []];
            if ($description === null) {
                continue;
            }

            $table->update(['is_enabled' => true, 'description' => $table->description ?: $description]);
            foreach (DbColumn::where('table_id', $table->id)->get() as $column) {
                if (!$column->description && isset($columns[$column->column_name])) {
                    $column->update(['description' => $columns[$column->column_name]]);
                }
            }
        }

        $bot = BotProfile::find(BotProfile::CONSOLE_ID);
        if ($bot) {
            DB::table('bot_db_connection')->insertOrIgnore(['bot_id' => $bot->id, 'connection_id' => $connection->id]);
            $bot->forceFill(['db_query_enabled' => true])->save();
        }

        return $connection;
    }

    /** Whether a table is one of the console's secret-holding ones. */
    public static function isSecret(DbTable $table): bool
    {
        return self::is($table->connection) && in_array(strtolower($table->table_name), self::NEVER, true);
    }

    public static function is(DbConnection $connection): bool
    {
        return (bool) (($connection->options ?? [])['console'] ?? false);
    }
}
