<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return view('dashboard', [
                'hasSystems' => false,
                'systemCount' => 0,
                'botCount' => 0,
                'activeBotCount' => 0,
                'conversationCount' => 0,
                'messageCount' => 0,
                'recentBots' => collect(),
                'recentConversations' => collect(),
            ]);
        }

        // Metrics scoped to active system
        $botProfilesQuery = BotProfile::where('system_id', $activeSystem->id);
        $botCount = (clone $botProfilesQuery)->count();
        $activeBotCount = (clone $botProfilesQuery)->where('is_active', true)->count();

        $conversationCount = ChatConversation::whereIn('bot_id', function ($query) use ($activeSystem) {
            $query->select('id')->from('bot_profiles')->where('system_id', $activeSystem->id);
        })->count();

        $messageCount = ChatMessage::whereIn('conversation_id', function ($query) use ($activeSystem) {
            $query->select('id')->from('chat_conversations')->whereIn('bot_id', function ($q2) use ($activeSystem) {
                $q2->select('id')->from('bot_profiles')->where('system_id', $activeSystem->id);
            });
        })->count();

        $recentBots = (clone $botProfilesQuery)->latest()->take(5)->get();

        $recentConversations = ChatConversation::with('bot')
            ->whereIn('bot_id', function ($query) use ($activeSystem) {
                $query->select('id')->from('bot_profiles')->where('system_id', $activeSystem->id);
            })
            ->latest()
            ->take(5)
            ->get();

        $systemCount = $user->isSuperAdmin() ? System::count() : $user->systems()->count();

        return view('dashboard', [
            'hasSystems' => true,
            'systemCount' => $systemCount,
            'botCount' => $botCount,
            'activeBotCount' => $activeBotCount,
            'conversationCount' => $conversationCount,
            'messageCount' => $messageCount,
            'recentBots' => $recentBots,
            'recentConversations' => $recentConversations,
        ]);
    }
}
