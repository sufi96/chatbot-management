<?php

namespace App\Controllers;

abstract class BaseController {
    protected function render(string $view, array $data = []): void {
        extract($data);
        $viewFile = dirname(__DIR__, 2) . '/views/' . $view . '.php';
        $layoutFile = dirname(__DIR__, 2) . '/views/layout.php';

        if (!file_exists($viewFile)) {
            die("View file not found: {$viewFile}");
        }

        // Render content buffer
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        // Render layout
        require $layoutFile;
    }

    protected function redirect(string $url): void {
        header("Location: {$url}");
        exit;
    }

    protected function json(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
