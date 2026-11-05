<?php

namespace App\Assistant;

/**
 * What the console assistant does about each console route.
 *
 *   tool:<name>[,<name>]   the assistant does this, with these tools
 *   later: <reason>        not yet; the to-do list for the next upgrade
 *   never: <reason>        deliberately not
 *
 * Every named route must be here (AssistantCoverageTest), and every entry in
 * docs/admin-assistant.md. When you add a console feature, add its tools to
 * Registry and decide its routes here: that is how the assistant grows with
 * the console instead of falling behind it.
 */
class Coverage
{
    private const SECRET = 'never: carries a secret, and a chat transcript is no place for one';
    private const DELETE = 'later: deleting stays a deliberate act on its page in this version';
    private const PAGE = 'never: a page or form, not an action; its data is read by the tools named on its actions';

    /** Tools that serve the assistant itself rather than a console route. */
    public const STANDALONE = ['whoami', 'recent_assistant_actions'];

    public const ROUTES = [
        // Signing in, files and the engine's own calls.
        'login' => 'never: signing in is the person\'s own act',
        'logout' => 'never: signing out is the person\'s own act',
        'storage.local' => 'never: file streaming for the framework',
        'storage.local.upload' => 'never: file streaming for the framework',
        'internal.db.query' => 'never: the engine\'s own call into the portal',
        'internal.assistant.tools' => 'never: the assistant\'s own channel',
        'internal.assistant.call' => 'never: the assistant\'s own channel',

        // Overview.
        'dashboard' => 'tool:list_bots,usage_summary',
        'analytics.index' => 'tool:usage_summary',

        // Workspaces and people.
        'systems.index' => 'tool:list_workspaces',
        'systems.switch' => 'never: changes which workspace the console pages show, not the system',
        'systems.store' => 'later: create a workspace',
        'systems.update' => 'later: rename a workspace or change its allowed sites',
        'systems.destroy' => self::DELETE,
        'systems.users' => 'tool:list_members',
        'systems.users.assign' => 'later: add a person to a workspace',
        'systems.users.update' => 'later: change a person\'s role in a workspace',
        'systems.users.remove' => 'later: remove a person from a workspace',
        'users.index' => 'tool:list_users',
        'users.store' => self::SECRET . ' (a password)',
        'users.update' => self::SECRET . ' (a password)',
        'users.destroy' => self::DELETE,

        // Bots.
        'bots.index' => 'tool:list_bots',
        'bots.create' => self::PAGE,
        'bots.store' => 'later: create a bot',
        'bots.edit' => 'tool:get_bot',
        'bots.update' => 'tool:set_bot_active',
        'bots.destroy' => self::DELETE,
        'bots.brain' => 'never: the old Behaviour address, now a redirect to its tab',
        'bots.settings' => 'tool:get_bot',
        'bots.brain.update' => 'tool:set_bot_sources,set_file_settings',
        'bots.brain.cache.clear' => 'tool:clear_answer_cache',
        'bots.brain.voice-preview' => 'never: plays audio in the page',
        'bots.embed' => 'later: hand over a bot\'s embed snippet',
        'admin.bots.index' => 'tool:list_bots',
        'admin.bots.destroy' => self::DELETE,
        'admin.bots.restore' => 'later: restore a deleted bot',
        'admin.bots.purge' => self::DELETE,

        // Conversations.
        'logs.index' => 'tool:list_conversations,failed_tool_calls',
        'logs.transcript' => 'tool:get_conversation',
        'logs.file' => 'later: hand over a file from a transcript',

        // Knowledge.
        'kb.index' => 'tool:list_knowledge',
        'kb.store' => 'later: create a collection',
        'kb.show' => 'tool:list_knowledge',
        'kb.destroy' => self::DELETE,
        'kb.sources.store' => 'later: add a text or Q&A source',
        'kb.sources.upload' => 'later: add a file source (needs files from the chat)',
        'kb.sources.show' => 'tool:list_knowledge',
        'kb.sources.update' => 'later: edit a source',
        'kb.sources.download' => 'later: hand over a source\'s file',
        'kb.sources.reindex' => 'tool:reindex_knowledge_source',
        'kb.sources.destroy' => self::DELETE,
        'kb.playground' => self::PAGE,
        'kb.playground.run' => 'later: try a search against a collection',

        // Connectors, skills and keys.
        'connectors.index' => 'tool:list_connectors',
        'connectors.store' => self::SECRET . ' (connector auth)',
        'connectors.update' => self::SECRET . ' (connector auth)',
        'connectors.destroy' => self::DELETE,
        'connectors.test' => 'later: test a connector',
        'connectors.import' => 'later: re-import a connector\'s tools',
        'connector-tools.update' => 'tool:set_connector_tool_enabled',
        'mcp-keys.store' => self::SECRET . ' (an MCP key, shown once)',
        'mcp-keys.destroy' => self::DELETE,
        'skills.index' => 'tool:list_skills',
        'skills.store' => 'later: write a skill',
        'skills.draft' => 'later: draft a skill with a model',
        'skills.upload' => 'later: upload a skill file',
        'skills.update' => 'later: edit a skill',
        'skills.destroy' => self::DELETE,
        'skills.mode' => 'later: change a skill\'s mode',
        'skills.copy' => 'later: copy a built-in skill',
        'skills.download' => 'later: hand over a skill file',

        // Databases.
        'databases.index' => 'tool:list_databases',
        'databases.store' => self::SECRET . ' (database credentials)',
        'databases.update' => self::SECRET . ' (database credentials)',
        'databases.destroy' => self::DELETE,
        'databases.test' => 'later: test a connection',
        'databases.test-draft' => self::SECRET . ' (database credentials)',
        'databases.toggle' => 'later: switch a connection on or off',
        'databases.schema' => 'tool:list_databases',
        'databases.introspect' => 'later: read a database\'s tables again',
        'databases.tables.store' => 'later: describe a table',
        'databases.tables.readable' => 'later: choose readable tables',
        'databases.tables.update' => 'later: describe a table',
        'databases.tables.destroy' => self::DELETE,
        'databases.columns.store' => 'later: describe a column',
        'databases.columns.update' => 'later: describe a column',
        'databases.columns.destroy' => self::DELETE,
        'databases.playground' => self::PAGE,
        'databases.playground.run' => 'later: try a question against a database',

        // Providers, search keys and platform settings.
        'providers.store' => self::SECRET . ' (a provider key)',
        'providers.update' => self::SECRET . ' (a provider key)',
        'providers.destroy' => self::DELETE,
        'providers.models' => 'later: list a provider\'s models',
        'providers.test' => 'later: test a provider',
        'admin.providers.store' => self::SECRET . ' (a provider key)',
        'admin.providers.update' => self::SECRET . ' (a provider key)',
        'admin.providers.destroy' => self::DELETE,
        'web-search-keys.store' => self::SECRET . ' (a search key)',
        'web-search-keys.update' => self::SECRET . ' (a search key)',
        'web-search-keys.destroy' => self::DELETE,
        'web-search-keys.test' => 'later: test a search key',
        'admin.settings' => 'later: read the platform settings (super admin)',
        'admin.settings.update' => 'later: change platform settings (super admin; some hold secrets)',
        'admin.settings.test' => 'later: test a model role',
        'admin.settings.models' => 'later: list a model server\'s models',
        'admin.settings.reindex' => 'later: re-index every knowledge base',
        'admin.settings.voice-test' => 'never: plays audio in the page',
        'admin.settings.speech-server' => 'later: check the speech server',
    ];

    public const START = '<!-- coverage:start (written by php artisan assistant:coverage; do not edit by hand) -->';

    public const END = '<!-- coverage:end -->';

    public static function page(): string
    {
        return base_path('../docs/admin-assistant.md');
    }

    /** The generated part of docs/admin-assistant.md: the tools, then every route by decision. */
    public static function section(): string
    {
        $out = [self::START, '', '### Tools', '', '| Tool | Changes things | Least role | What it does |', '|---|---|---|---|'];
        foreach (Registry::all() as $tool) {
            $role = $tool->superOnly ? 'super_admin' : $tool->minRole;
            $out[] = "| `{$tool->name}` | " . ($tool->writes ? 'yes, after Confirm' : 'no') . " | {$role} | "
                . str_replace('|', '/', $tool->description) . ' |';
        }

        $groups = ['tool' => 'Covered', 'later' => 'Not yet (later)', 'never' => 'Never'];
        foreach ($groups as $kind => $title) {
            $rows = collect(self::ROUTES)->filter(fn ($d) => str_starts_with($d, $kind === 'tool' ? 'tool:' : "{$kind}: "));
            $out[] = '';
            $out[] = "### {$title} ({$rows->count()} routes)";
            $out[] = '';
            foreach ($rows as $route => $decision) {
                $out[] = $kind === 'tool'
                    ? "- `{$route}`: " . collect(explode(',', substr($decision, 5)))->map(fn ($t) => "`{$t}`")->implode(', ')
                    : "- `{$route}`: " . substr($decision, strlen($kind) + 2);
            }
        }
        $out[] = '';
        $out[] = self::END;

        return implode("\n", $out);
    }

    /** docs/admin-assistant.md with its generated part replaced. */
    public static function write(): void
    {
        $page = str_replace("\r\n", "\n", file_get_contents(self::page()));
        $start = strpos($page, '<!-- coverage:start');
        $end = strpos($page, self::END);
        if ($start === false || $end === false) {
            throw new \RuntimeException('docs/admin-assistant.md has no coverage markers.');
        }

        file_put_contents(self::page(), substr($page, 0, $start) . self::section() . substr($page, $end + strlen(self::END)));
    }
}
