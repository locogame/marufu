<?php
declare(strict_types=1);

require_once __DIR__ . '/room_common.php';

room_json_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    room_fail(405, 'method_not_allowed');
}

$input = room_read_json_body();
$code = room_clean_code((string)($input['code'] ?? ''));
$token = (string)($input['token'] ?? '');
$action = (string)($input['action'] ?? '');
$payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

if ($code === '' || $token === '') {
    room_fail(422, 'invalid_request');
}

$allowedActions = ['open_questions', 'ask_question', 'peek', 'declare'];
if (!in_array($action, $allowedActions, true)) {
    room_fail(422, 'invalid_action');
}

$pdo = suiri_db();
$pdo->beginTransaction();
try {
    $room = room_fetch($pdo, $code, true);
    if ($room === null) {
        $pdo->rollBack();
        room_fail(404, 'room_not_found');
    }
    if ($room['status'] !== 'active' || $room['state_json'] === null) {
        $pdo->rollBack();
        room_fail(409, 'room_not_active');
    }

    $role = room_resolve_role($room, $token);
    if ($role === 'spectator') {
        $pdo->rollBack();
        room_fail(403, 'spectators_cannot_act');
    }

    $state = json_decode((string)$room['state_json'], true);

    if ($state['over']) {
        $pdo->rollBack();
        room_fail(409, 'game_already_over');
    }
    if ($state['turn'] !== $role) {
        $pdo->rollBack();
        room_fail(409, 'not_your_turn');
    }

    $opponent = room_opponent($role);
    $initialQuestion = $state['initialQuestionPending'] && $role === $state['firstPlayer'];
    $newStatus = 'active';

    if ($action === 'open_questions') {
        if ($state['offers'][$role] === null) {
            if ($initialQuestion) {
                $state['offers'][$role] = array_values(suiri_initial_cards());
            } else {
                $normal = suiri_normal_cards();
                $unusedNos = array_values(array_diff(array_keys($normal), array_keys($state['asked'][$role])));
                shuffle($unusedNos);
                $chosenNos = array_slice($unusedNos, 0, 4);
                $state['offers'][$role] = array_map(fn($no) => $normal[$no], $chosenNos);
            }
        }
    } elseif ($action === 'ask_question') {
        $key = (string)($payload['key'] ?? '');
        $variantId = (string)($payload['variantId'] ?? '');
        $offer = $state['offers'][$role];
        if ($offer === null || !in_array($key, $offer, true)) {
            $pdo->rollBack();
            room_fail(422, 'question_not_offered');
        }
        if (!in_array($variantId, suiri_variants_for($key), true)) {
            $pdo->rollBack();
            room_fail(422, 'invalid_variant');
        }
        if ($key === 'card-common') {
            $shownCard = (int) substr($variantId, 1);
            if (!in_array($shownCard, $state['hands'][$role], true)) {
                $pdo->rollBack();
                room_fail(422, 'card_not_in_hand');
            }
        }

        $normalCards = suiri_normal_cards();
        $initialCards = suiri_initial_cards();
        $keyToNo = array_flip($normalCards) + array_flip($initialCards);
        $no = $keyToNo[$key];
        $reciprocal = array_key_exists($no, $initialCards);

        if ($initialQuestion && !$reciprocal) {
            $pdo->rollBack();
            room_fail(422, 'must_ask_reciprocal_first');
        }
        if (!$reciprocal && array_key_exists($no, $state['asked'][$role])) {
            $pdo->rollBack();
            room_fail(409, 'question_already_used');
        }

        // suiri_answer は手札をビットマスク（int）で受け取るので、カード番号の配列から変換する。
        $answer = suiri_answer($key, $variantId, room_mask_of($state['hands'][$opponent]));
        $state['asked'][$role][$no] = ['key' => $key, 'variantId' => $variantId];

        $logEntry = [
            'actor' => $role,
            'type' => 'question',
            'key' => $key,
            'variantId' => $variantId,
            'reciprocal' => $reciprocal,
            'answer' => $answer,
        ];

        if ($reciprocal) {
            $selfAnswer = suiri_answer($key, $variantId, room_mask_of($state['hands'][$role]));
            $logEntry['selfAnswer'] = $selfAnswer;
            $state['initialQuestionPending'] = false;
        }

        $state['log'][] = $logEntry;
        $state['offers'][$role] = null;
        $state['turn'] = $opponent;
    } elseif ($action === 'peek') {
        if ($initialQuestion) {
            $pdo->rollBack();
            room_fail(422, 'cannot_peek_during_initial_question');
        }
        if (count($state['deck']) === 0) {
            $pdo->rollBack();
            room_fail(409, 'deck_empty');
        }
        $peeked = array_shift($state['deck']);
        $state['seen'][$role][] = $peeked;
        // ログには「山札を見た」事実だけを記録し、カードの中身は本人にしか返さない
        // （yourSeen経由。room_build_viewは相手側にはseenの中身を渡さない）。
        $state['log'][] = ['actor' => $role, 'type' => 'peek'];
        $state['turn'] = $opponent;
    } elseif ($action === 'declare') {
        if ($initialQuestion) {
            $pdo->rollBack();
            room_fail(422, 'cannot_declare_during_initial_question');
        }
        $cardIndices = is_array($payload['cardIndices'] ?? null) ? $payload['cardIndices'] : [];
        $handSize = (int)$room['hand_size'];
        $unique = array_values(array_unique(array_map('intval', $cardIndices)));
        if (count($unique) !== $handSize || count($cardIndices) !== $handSize) {
            $pdo->rollBack();
            room_fail(422, 'invalid_declare_size');
        }
        foreach ($unique as $i) {
            if ($i < 0 || $i > 15) {
                $pdo->rollBack();
                room_fail(422, 'invalid_card_index');
            }
        }

        $mask = room_mask_of($unique);
        $opponentMask = room_mask_of($state['hands'][$opponent]);
        $correct = $mask === $opponentMask;

        $state['over'] = true;
        $state['reason'] = $correct ? 'correct' : 'wrong';
        $state['winner'] = $correct ? $role : $opponent;
        $state['log'][] = [
            'actor' => $role,
            'type' => 'declare',
            'cardIndices' => $unique,
            'correct' => $correct,
        ];
        $newStatus = 'finished';
    }

    $table = suiri_table('rooms');
    $stmt = $pdo->prepare(
        "UPDATE `$table` SET state_json = ?, status = ?, version = version + 1, updated_at = NOW() WHERE room_code = ?"
    );
    $stmt->execute([json_encode($state), $newStatus, $code]);
    $pdo->commit();

    $room['state_json'] = json_encode($state);
    $room['status'] = $newStatus;
    $room['version'] = (int)$room['version'] + 1;
    echo json_encode(room_build_view($room, $state, $role));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
