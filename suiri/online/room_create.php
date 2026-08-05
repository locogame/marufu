<?php
declare(strict_types=1);

require_once __DIR__ . '/room_common.php';

room_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    room_fail(405, 'method_not_allowed');
}

$input = room_read_json_body();
$handSize = (int)($input['handSize'] ?? 4);
if ($handSize !== 3 && $handSize !== 4) {
    room_fail(422, 'invalid_hand_size');
}

$pdo = suiri_db();
room_gc($pdo);

$table = suiri_table('rooms');
$token = room_generate_token();
$tokenHash = room_hash_token($token);

// 部屋コードの衝突を避けつつ発行（実用上ほぼ1回で決まる）。
$code = null;
for ($attempt = 0; $attempt < 10; $attempt++) {
    $candidate = room_generate_code();
    $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE room_code = ?");
    $stmt->execute([$candidate]);
    if ($stmt->fetch() === false) {
        $code = $candidate;
        break;
    }
}
if ($code === null) {
    room_fail(500, 'code_generation_failed');
}

$stmt = $pdo->prepare(
    "INSERT INTO `$table` (room_code, hand_size, status, player1_token_hash, state_json, version, created_at, updated_at)
     VALUES (?, ?, 'waiting', ?, NULL, 0, NOW(), NOW())"
);
$stmt->execute([$code, $handSize, $tokenHash]);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);
$shareUrl = rtrim($base, '/') . '/suiri-online.html?code=' . $code;

echo json_encode([
    'ok' => true,
    'roomCode' => $code,
    'playerToken' => $token,
    'handSize' => $handSize,
    'shareUrl' => $shareUrl,
]);
