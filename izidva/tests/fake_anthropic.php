<?php
// Заглушка Anthropic API для тестов: php -S 127.0.0.1:PORT tests/fake_anthropic.php
$body = json_decode((string)file_get_contents('php://input'), true);
file_put_contents(sys_get_temp_dir() . '/izidva_fake_req.json', json_encode(['uri' => $_SERVER['REQUEST_URI'],
    'beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? null, 'body' => $body], JSON_UNESCAPED_UNICODE));
$insight = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.3, 'w_trend' => 0.9, 'w_liquidation' => 0.4,
    'grid_mode' => 'long', 'grid_step_atr' => 9.0, 'risk_mult' => 1.0, 'summary' => 'Тренд вверх.'];
header('Content-Type: application/json');
echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? 'claude-opus-5',
    'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => json_encode($insight, JSON_UNESCAPED_UNICODE)]],
    'stop_reason' => 'end_turn', 'stop_sequence' => null,
    'usage' => ['input_tokens' => 1000, 'output_tokens' => 400, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]);
