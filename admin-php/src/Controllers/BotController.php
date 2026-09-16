<?php

namespace App\Controllers;

use App\Database;
use PDO;

class BotController extends BaseController {
    public function index(): void {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT b.*, s.name as system_name 
            FROM bot_profiles b
            LEFT JOIN systems s ON b.system_id = s.id
            ORDER BY b.created_at DESC
        ");
        $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->render('bots/index', ['bots' => $bots]);
    }

    public function create(): void {
        $db = Database::getConnection();
        $systems = $db->query("SELECT id, name FROM systems ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('bots/form', [
            'isEdit' => false,
            'bot' => [
                'id' => '',
                'system_id' => $systems[0]['id'] ?? '',
                'name' => '',
                'system_prompt' => "You are a helpful, courteous, and accurate AI assistant.",
                'provider_type' => 'ollama',
                'base_url' => 'http://localhost:11434/v1',
                'api_key' => '',
                'model_name' => 'llama3.2',
                'temperature' => 0.7,
                'max_tokens' => 1024,
                'widget_title' => 'Support Assistant',
                'widget_greeting' => 'Hello! How can I help you today?',
                'widget_primary_color' => '#4F46E5',
                'widget_position' => 'bottom-right',
                'is_active' => 1
            ],
            'systems' => $systems
        ]);
    }

    public function store(): void {
        $db = Database::getConnection();

        $id = 'bot_' . bin2hex(random_bytes(6));
        $systemId = $_POST['system_id'] ?? '';
        $name = trim($_POST['name'] ?? 'Untitled Bot');
        $systemPrompt = trim($_POST['system_prompt'] ?? '');
        $providerType = $_POST['provider_type'] ?? 'ollama';
        $baseUrl = trim($_POST['base_url'] ?? 'http://localhost:11434/v1');
        $apiKey = trim($_POST['api_key'] ?? '');
        $modelName = trim($_POST['model_name'] ?? 'llama3.2');
        $temperature = floatval($_POST['temperature'] ?? 0.7);
        $maxTokens = intval($_POST['max_tokens'] ?? 1024);
        $widgetTitle = trim($_POST['widget_title'] ?? $name);
        $widgetGreeting = trim($_POST['widget_greeting'] ?? 'Hello!');
        $widgetPrimaryColor = trim($_POST['widget_primary_color'] ?? '#4F46E5');
        $widgetPosition = $_POST['widget_position'] ?? 'bottom-right';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $db->prepare("
            INSERT INTO bot_profiles (
                id, system_id, name, system_prompt, provider_type, base_url, api_key,
                model_name, temperature, max_tokens, widget_title, widget_greeting,
                widget_primary_color, widget_position, is_active
            ) VALUES (
                :id, :system_id, :name, :system_prompt, :provider_type, :base_url, :api_key,
                :model_name, :temperature, :max_tokens, :widget_title, :widget_greeting,
                :widget_primary_color, :widget_position, :is_active
            )
        ");

        $stmt->execute([
            ':id' => $id,
            ':system_id' => $systemId,
            ':name' => $name,
            ':system_prompt' => $systemPrompt,
            ':provider_type' => $providerType,
            ':base_url' => $baseUrl,
            ':api_key' => $apiKey,
            ':model_name' => $modelName,
            ':temperature' => $temperature,
            ':max_tokens' => $maxTokens,
            ':widget_title' => $widgetTitle,
            ':widget_greeting' => $widgetGreeting,
            ':widget_primary_color' => $widgetPrimaryColor,
            ':widget_position' => $widgetPosition,
            ':is_active' => $isActive
        ]);

        $this->redirect("/bots/{$id}/embed?success=Bot+profile+created+successfully");
    }

    public function edit(array $params): void {
        $id = $params['id'] ?? '';
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM bot_profiles WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bot) {
            $this->redirect('/bots?error=Bot+not+found');
        }

        $systems = $db->query("SELECT id, name FROM systems ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $this->render('bots/form', [
            'isEdit' => true,
            'bot' => $bot,
            'systems' => $systems
        ]);
    }

    public function update(array $params): void {
        $id = $params['id'] ?? '';
        $db = Database::getConnection();

        $systemId = $_POST['system_id'] ?? '';
        $name = trim($_POST['name'] ?? 'Untitled Bot');
        $systemPrompt = trim($_POST['system_prompt'] ?? '');
        $providerType = $_POST['provider_type'] ?? 'ollama';
        $baseUrl = trim($_POST['base_url'] ?? 'http://localhost:11434/v1');
        $apiKey = trim($_POST['api_key'] ?? '');
        $modelName = trim($_POST['model_name'] ?? 'llama3.2');
        $temperature = floatval($_POST['temperature'] ?? 0.7);
        $maxTokens = intval($_POST['max_tokens'] ?? 1024);
        $widgetTitle = trim($_POST['widget_title'] ?? $name);
        $widgetGreeting = trim($_POST['widget_greeting'] ?? 'Hello!');
        $widgetPrimaryColor = trim($_POST['widget_primary_color'] ?? '#4F46E5');
        $widgetPosition = $_POST['widget_position'] ?? 'bottom-right';
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $db->prepare("
            UPDATE bot_profiles SET
                system_id = :system_id,
                name = :name,
                system_prompt = :system_prompt,
                provider_type = :provider_type,
                base_url = :base_url,
                api_key = :api_key,
                model_name = :model_name,
                temperature = :temperature,
                max_tokens = :max_tokens,
                widget_title = :widget_title,
                widget_greeting = :widget_greeting,
                widget_primary_color = :widget_primary_color,
                widget_position = :widget_position,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id,
            ':system_id' => $systemId,
            ':name' => $name,
            ':system_prompt' => $systemPrompt,
            ':provider_type' => $providerType,
            ':base_url' => $baseUrl,
            ':api_key' => $apiKey,
            ':model_name' => $modelName,
            ':temperature' => $temperature,
            ':max_tokens' => $maxTokens,
            ':widget_title' => $widgetTitle,
            ':widget_greeting' => $widgetGreeting,
            ':widget_primary_color' => $widgetPrimaryColor,
            ':widget_position' => $widgetPosition,
            ':is_active' => $isActive
        ]);

        $this->redirect("/bots/{$id}/edit?success=Bot+profile+updated+successfully");
    }

    public function delete(array $params): void {
        $id = $params['id'] ?? '';
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM bot_profiles WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $this->redirect('/bots?success=Bot+profile+deleted+successfully');
    }

    public function embed(array $params): void {
        $id = $params['id'] ?? '';
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT b.*, s.name as system_name, s.allowed_origins 
            FROM bot_profiles b
            LEFT JOIN systems s ON b.system_id = s.id
            WHERE b.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bot) {
            $this->redirect('/bots?error=Bot+not+found');
        }

        $apiHost = getenv('API_HOST_URL') ?: 'http://localhost:8000';

        $this->render('bots/embed', [
            'bot' => $bot,
            'apiHost' => $apiHost
        ]);
    }
}
