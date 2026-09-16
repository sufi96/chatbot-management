<?php

namespace App\Controllers;

use App\Database;
use PDO;

class LogController extends BaseController {
    public function index(): void {
        $db = Database::getConnection();

        $botFilter = $_GET['bot_id'] ?? '';
        $sql = "
            SELECT c.*, b.name as bot_name, s.name as system_name,
                   (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id) as msg_count,
                   (SELECT content FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.created_at ASC LIMIT 1) as first_user_msg
            FROM chat_conversations c
            JOIN bot_profiles b ON c.bot_id = b.id
            JOIN systems s ON b.system_id = s.id
        ";

        $params = [];
        if ($botFilter) {
            $sql .= " WHERE c.bot_id = :bot_id";
            $params[':bot_id'] = $botFilter;
        }

        $sql .= " ORDER BY c.created_at DESC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bots = $db->query("SELECT id, name FROM bot_profiles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('logs/index', [
            'conversations' => $conversations,
            'bots' => $bots,
            'selectedBot' => $botFilter
        ]);
    }

    public function transcript(array $params): void {
        $convId = $params['id'] ?? '';
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT m.* 
            FROM chat_messages m
            WHERE m.conversation_id = :id
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([':id' => $convId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json(['messages' => $messages]);
    }
}
