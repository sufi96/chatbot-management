<?php

namespace App\Controllers;

use App\Database;
use PDO;

class SystemController extends BaseController {
    public function index(): void {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT s.*, 
                   COUNT(b.id) as bot_count
            FROM systems s
            LEFT JOIN bot_profiles b ON s.id = b.system_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ");
        $systems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->render('systems/index', ['systems' => $systems]);
    }

    public function store(): void {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allowedOrigins = trim($_POST['allowed_origins'] ?? '*') ?: '*';

        if (empty($name)) {
            $this->redirect('/systems?error=Name+is+required');
        }

        $id = 'sys_' . bin2hex(random_bytes(6));
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO systems (id, name, description, allowed_origins) 
            VALUES (:id, :name, :description, :allowed_origins)
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':allowed_origins' => $allowedOrigins
        ]);

        $this->redirect('/systems?success=System+created+successfully');
    }

    public function update(array $params): void {
        $id = $params['id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allowedOrigins = trim($_POST['allowed_origins'] ?? '*') ?: '*';

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE systems 
            SET name = :name, description = :description, allowed_origins = :allowed_origins, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':description' => $description,
            ':allowed_origins' => $allowedOrigins
        ]);

        $this->redirect('/systems?success=System+updated+successfully');
    }

    public function delete(array $params): void {
        $id = $params['id'] ?? '';
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM systems WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $this->redirect('/systems?success=System+deleted+successfully');
    }
}
