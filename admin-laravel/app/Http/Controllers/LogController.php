<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\BotProfile;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return redirect()->route('systems.index');
        }

        $botId = $request->query('bot_id');

        $query = ChatConversation::with(['bot', 'messages'])
            ->whereIn('bot_id', function ($q) use ($activeSystem) {
                $q->select('id')->from('bot_profiles')->where('system_id', $activeSystem->id);
            });

        if ($botId) {
            $query->where('bot_id', $botId);
        }

        $conversations = $query->latest()->paginate(25);
        $bots = BotProfile::where('system_id', $activeSystem->id)->orderBy('name')->get();

        return view('logs.index', [
            'conversations' => $conversations,
            'bots' => $bots,
            'selectedBot' => $botId,
            'activeSystem' => $activeSystem,
        ]);
    }

    public function transcript(string $id)
    {
        $conversation = ChatConversation::with(['bot', 'messages'])->findOrFail($id);

        return response()->json([
            'bot_name' => $conversation->bot->name ?? 'Bot',
            'messages' => $conversation->messages,
        ]);
    }
}
