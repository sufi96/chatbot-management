<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\KbSource;
use App\Models\User;
use App\Services\EngineClient;

class ReindexKnowledgeSource extends Tool
{
    public string $name = 'reindex_knowledge_source';

    public string $description = 'Index one knowledge source again, such as one that failed. The id comes from list_knowledge.';

    public bool $writes = true;

    public string $minRole = 'editor';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'source_id' => ['type' => 'string', 'description' => 'The source id from list_knowledge.'],
        ], 'required' => ['source_id']];
    }

    public function run(User $user, array $args): string
    {
        $id = (string) ($args['source_id'] ?? '');
        $source = KbSource::with('collection')->whereHas('collection', fn ($q) => $q->whereIn('system_id',
            Lookup::workspaces($user, 'editor')->pluck('id')))->find($id);
        if (!$source) {
            throw new Refusal("No knowledge source {$id} that you can change.");
        }

        $source->update(['status' => 'pending', 'error_message' => null]);
        $started = EngineClient::indexSource($source->id);

        return $started ? "Re-indexing \"{$source->title}\" started. It runs in the background."
            : "\"{$source->title}\" is marked to index again, but the engine did not answer. It will be picked up when the engine is back.";
    }
}
