<?php

namespace App\Controllers;

use App\Database;
use PDO;

class DashboardController extends BaseController {
    public function index(): void {
        $db = Database::getConnection();

        // Summary metrics
        $systemCount = $db->query("SELECT COUNT(*) FROM systems")->fetchColumn() ?: 0;
        $botCount = $db->query("SELECT COUNT(*) FROM bot_profiles")->fetchColumn() ?: 0;
        $activeBotCount = $db->query("SELECT COUNT(*) FROM bot_profiles WHERE is_active = 1")->fetchColumn() ?: 0;
        $conversationCount = $db->query("SELECT COUNT(*) FROM chat_conversations")->fetchColumn() ?: 0;
        $messageCount = $db->query("SELECT COUNT(*) FROM chat_messages")->fetchColumn() ?: 0;

        // Recent bots
        $stmt = $db->query("
            SELECT b.*, s.name as system_name 
            FROM bot_profiles b
            LEFT JOIN systems s ON b.system_id = s.id
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $recentBots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent conversations
        $stmtConv = $db->query("
            SELECT c.*, b.name as bot_name, 
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id) as msg_count
            FROM chat_conversations c
            LEFT JOIN bot_profiles b ON c.bot_id = b.id
            ORDER BY c.created_at DESC
            LIMIT 5
        ");
        $recentConversations = $stmtConv->fetchAll(PDO::FETCH_ASSOC);

        $this->render('dashboard', [
            'systemCount' => $systemCount,
            'botCount' => $botCount,
            'activeBotCount' => $activeBotCount,
            'conversationCount' => $conversationCount,
            'messageCount' => $messageCount,
            'recentBots' => $recentBots,
            'recentConversations' => $recentConversations
        ]);
    }
}
