<?php
declare(strict_types=1);

require_once __DIR__ . '/room_common.php';

room_json_headers();

$code = room_clean_code((string)($_GET['code'] ?? ''));
$token = isset($_GET['token']) ? (string)$_GET['token'] : null;

if ($code === '') {
    room_fail(422, 'invalid_code');
}

$pdo = suiri_db();
$room = room_fetch($pdo, $code);
if ($room === null) {
    room_fail(404, 'room_not_found');
}

if ($room['status'] === 'waiting' || $room['state_json'] === null) {
    echo json_encode([
        'ok' => true,
        'roomCode' => $code,
        'handSize' => (int)$room['hand_size'],
        'status' => $room['status'],
        'version' => (int)$room['version'],
        'role' => room_resolve_role($room, $token),
    ]);
    return;
}

$state = json_decode((string)$room['state_json'], true);
$role = room_resolve_role($room, $token);
echo json_encode(room_build_view($room, $state, $role));
