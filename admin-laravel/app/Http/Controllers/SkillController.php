<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Services\EngineClient;
use App\Models\Skill;
use App\Support\SkillFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

/**
 * A workspace's skills, from the Skills page. JSON for its modals.
 *
 * A skill's text is instructions a bot will follow, so writing one is for the
 * workspace's system admins, as connectors are. Built-ins belong to no
 * workspace: they are read and copied, never changed.
 */
class SkillController extends Controller
{
    private const ZIP_BYTES = 1048576;

    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Select a workspace first.');
        }

        return view('skills.index', [
            'activeSystem' => $activeSystem,
            'skills' => Skill::where('system_id', $activeSystem->id)->withCount('bots')->orderBy('name')->get(),
            'builtIns' => Skill::whereNull('system_id')->orderBy('name')->get(),
            'canManage' => $request->user()->canManageSystem($activeSystem->id, 'system_admin'),
            'providers' => $this->draftProviders($request, $activeSystem->id),
        ]);
    }

    /**
     * A first draft from the workspace's own model, loaded into the editor
     * unsaved: the admin reads and edits it before it becomes a skill.
     */
    public function draft(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $validated = $request->validate([
            'provider_id' => ['required', 'string'],
            'model' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        abort_unless($this->draftProviders($request, $systemId)->contains('id', $validated['provider_id']), 403);

        return response()->json(EngineClient::draftSkill(
            $validated['provider_id'], $validated['model'], $validated['description']));
    }

    /** This workspace's language-model providers, and the platform's for a super admin. */
    private function draftProviders(Request $request, string $systemId)
    {
        $superAdmin = $request->user()->isSuperAdmin();

        return AiProvider::serving('chat')
            ->where(fn ($q) => $q->where('system_id', $systemId)
                ->when($superAdmin, fn ($q) => $q->orWhereNull('system_id')))
            ->orderBy('name')->get(['id', 'name']);
    }

    /** A skill typed in the editor: the whole SKILL.md text. */
    public function store(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:60000'],
            'origin' => ['sometimes', 'in:typed,generated'],
        ]);

        return $this->create($systemId, $validated['content'], $validated['origin'] ?? 'typed');
    }

    /** A SKILL.md, or a zip holding one, at its root or one folder down. */
    public function upload(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $request->validate(['file' => ['required', 'file', 'max:1024']]);
        $file = $request->file('file');

        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return $this->create($systemId, (string) file_get_contents($file->getRealPath()), 'upload');
        }

        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            return $this->refuse('That zip could not be opened.');
        }

        // Read in memory and never extracted, so a name in the archive can
        // never reach the disk, however it is written.
        $found = [];
        $others = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $zip->close();

                return $this->refuse("The zip has a file outside its folder ({$name}).");
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (preg_match('#^([^/]+/)?SKILL\.md$#', $name)) {
                $found[] = $i;
            } else {
                $others[] = $name;
            }
        }

        if (count($found) !== 1) {
            $zip->close();

            return $this->refuse(count($found) === 0
                ? 'The zip has no SKILL.md at its root or in one folder.'
                : 'The zip holds more than one skill. Upload one at a time.');
        }

        $stat = $zip->statIndex($found[0]);
        if (($stat['size'] ?? 0) > self::ZIP_BYTES) {
            $zip->close();

            return $this->refuse('SKILL.md is over 1 MB.');
        }
        $text = (string) $zip->getFromIndex($found[0]);
        $zip->close();

        $response = $this->create($systemId, $text, 'upload');
        if ($others && $response->getStatusCode() === 200) {
            $data = $response->getData(true);
            $data['message'] .= ' Other files in the zip are not used yet: ' . implode(', ', array_slice($others, 0, 5))
                . (count($others) > 5 ? ', …' : '') . '.';
            $response->setData($data);
        }

        return $response;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $skill = Skill::findOrFail($id);
        abort_if($skill->isBuiltIn(), 403);
        $this->authorizeAdmin($request, $skill->system_id);

        $validated = $request->validate(['content' => ['required', 'string', 'max:60000']]);

        try {
            $parsed = SkillFile::parse($validated['content']);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->nameTaken($skill->system_id, $parsed['name'], $skill->id)) {
            return $this->refuse('This workspace already has a skill called ' . $parsed['name'] . '.');
        }

        $skill->update($parsed);

        return response()->json(['success' => true, 'message' => "{$skill->name} saved."]);
    }

    /** Loaded when a request matches, or added to every answer. */
    public function mode(Request $request, string $id): JsonResponse
    {
        $skill = Skill::findOrFail($id);
        abort_if($skill->isBuiltIn(), 403);
        $this->authorizeAdmin($request, $skill->system_id);

        $validated = $request->validate(['mode' => ['required', 'in:' . implode(',', array_keys(Skill::MODES))]]);
        $skill->update($validated);

        return response()->json(['success' => true, 'message' => $validated['mode'] === 'always'
            ? "{$skill->name} now shapes every answer." : "{$skill->name} loads when a request matches."]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $skill = Skill::findOrFail($id);
        abort_if($skill->isBuiltIn(), 403);
        $this->authorizeAdmin($request, $skill->system_id);

        $inUse = $skill->bots()->orderBy('name')->pluck('name');
        if ($inUse->isNotEmpty()) {
            return response()->json(['success' => false,
                'message' => 'Still used by ' . $inUse->join(', ', ' and ') . '. Take it off first.'], 409);
        }

        $name = $skill->name;
        $skill->delete();

        return response()->json(['success' => true, 'message' => "{$name} deleted."]);
    }

    /** A built-in, copied into the workspace, renamed -2, -3 if the name is taken. */
    public function copy(Request $request, string $id): JsonResponse
    {
        $builtIn = Skill::whereNull('system_id')->findOrFail($id);
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $name = $builtIn->name;
        for ($n = 2; $this->nameTaken($systemId, $name); $n++) {
            $name = "{$builtIn->name}-{$n}";
        }

        Skill::create([
            'id' => 'skl_' . Str::random(12), 'system_id' => $systemId, 'name' => $name,
            'description' => $builtIn->description, 'body' => $builtIn->body,
            'tools' => $builtIn->tools ?? [], 'origin' => 'builtin',
        ]);

        return response()->json(['success' => true, 'message' => "Copied as {$name}. Link the tools it needs on a bot."]);
    }

    public function download(Request $request, string $id)
    {
        $skill = Skill::findOrFail($id);
        abort_unless($skill->isBuiltIn() || $request->user()->canManageSystem($skill->system_id), 403);

        return response($skill->toFile(), 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="SKILL.md"',
        ]);
    }

    private function create(string $systemId, string $text, string $origin): JsonResponse
    {
        try {
            $parsed = SkillFile::parse($text);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->nameTaken($systemId, $parsed['name'])) {
            return $this->refuse('This workspace already has a skill called ' . $parsed['name'] . '.');
        }

        Skill::create($parsed + ['id' => 'skl_' . Str::random(12), 'system_id' => $systemId, 'origin' => $origin]);

        return response()->json(['success' => true, 'message' => "{$parsed['name']} saved."]);
    }

    private function nameTaken(string $systemId, string $name, ?string $ignoreId = null): bool
    {
        return Skill::where('system_id', $systemId)->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => ['content' => [$message]]], 422);
    }

    private function authorizeAdmin(Request $request, string $systemId): void
    {
        abort_unless($systemId !== '' && $request->user()->canManageSystem($systemId, 'system_admin'), 403);
    }
}
