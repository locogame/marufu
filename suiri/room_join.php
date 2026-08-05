<?php
declare(strict_types=1);

require_once __DIR__ . '/room_common.php';

room_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    room_fail(405, 'method_not_allowed');
}

$input = room_read_json_body();
$code = room_clean_code((string)($input['code'] ?? ''));
$wantsToPlay = !empty($input['wantsToPlay']);

if ($code === '') {
    room_fail(422, 'invalid_code');
}

$pdo = suiri_db();
$pdo->beginTransaction();
try {
    $room = room_fetch($pdo, $code, true);
    if ($room === null) {
        $pdo->rollBack();
        room_fail(404, 'room_not_found');
    }

    if ($wantsToPlay && $room['status'] === 'waiting' && $room['player2_token_hash'] === null) {
        $token = room_generate_token();
        $tokenHash = room_hash_token($token);
        $dealt = room_deal((int)$room['hand_size']);
        // 先手はランダムに決める。
        $firstPlayer = random_int(0, 1) === 0 ? 'p1' : 'p2';
        $state = room_new_state($dealt, $firstPlayer);

        $table = suiri_table('rooms');
        $stmt = $pdo->prepare(
            "UPDATE `$table`
             SET player2_token_hash = ?, status = 'active', state_json = ?, version = version + 1, updated_at = NOW()
             WHERE room_code = ?"
        );
        $stmt->execute([$tokenHash, json_encode($state), $code]);
        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'role' => 'p2',
            'playerToken' => $token,
            'handSize' => (int)$room['hand_size'],
            'roomCode' => $code,
        ]);
        return;
    }

    // 定員に達している、または観戦希望：観戦者として案内する。
    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'role' => 'spectator',
        'handSize' => (int)$room['hand_size'],
        'roomCode' => $code,
        'status' => $room['status'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
