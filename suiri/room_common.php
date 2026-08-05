<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/suiri_rules.php';

function room_json_headers(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function room_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '' || strlen($raw) > 8192) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function room_fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

function room_code_alphabet(): string
{
    // 0/O, 1/I/l のような紛らわしい文字を除いたアルファベット。
    return 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
}

function room_generate_code(): string
{
    $alphabet = room_code_alphabet();
    $len = strlen($alphabet);
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, $len - 1)];
    }
    return $code;
}

function room_clean_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    return substr($code, 0, 6);
}

function room_generate_token(): string
{
    return bin2hex(random_bytes(16));
}

function room_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function room_gc(PDO $pdo): void
{
    $table = suiri_table('rooms');
    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE updated_at < (NOW() - INTERVAL 1 DAY)");
    $stmt->execute();
}

// 部屋の役割判定：token が player1/player2 のハッシュと一致すれば 'p1'/'p2'、
// 一致しなければ（トークン無し含め）観戦者として 'spectator' を返す。
function room_resolve_role(array $room, ?string $token): string
{
    if ($token !== null && $token !== '') {
        $hash = room_hash_token($token);
        if ($room['player1_token_hash'] !== null && hash_equals($room['player1_token_hash'], $hash)) {
            return 'p1';
        }
        if ($room['player2_token_hash'] !== null && hash_equals($room['player2_token_hash'], $hash)) {
            return 'p2';
        }
    }
    return 'spectator';
}

function room_fetch(PDO $pdo, string $code, bool $forUpdate = false): ?array
{
    $table = suiri_table('rooms');
    $sql = "SELECT * FROM `$table` WHERE room_code = ?" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function room_new_state(array $handsAndDeck, string $firstPlayer): array
{
    return [
        'firstPlayer' => $firstPlayer,
        'turn' => $firstPlayer,
        'deck' => $handsAndDeck['deck'],
        'hands' => $handsAndDeck['hands'],
        'seen' => [
            'p1' => $handsAndDeck['initialSeen']['p1'],
            'p2' => $handsAndDeck['initialSeen']['p2'],
        ],
        'initialSeen' => $handsAndDeck['initialSeen'],
        'asked' => ['p1' => [], 'p2' => []],
        'offers' => ['p1' => null, 'p2' => null],
        'initialQuestionPending' => true,
        'log' => [],
        'over' => false,
        'reason' => null,
        'winner' => null,
    ];
}

// 16枚をシャッフルして両者の手札・初期確認2枚・共有山札に振り分ける。
function room_deal(int $handSize): array
{
    $cards = range(0, 15);
    for ($i = count($cards) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
    }
    $p1Hand = array_splice($cards, 0, $handSize);
    $p2Hand = array_splice($cards, 0, $handSize);
    $p1Seen = array_splice($cards, 0, 2);
    $p2Seen = array_splice($cards, 0, 2);
    sort($p1Hand);
    sort($p2Hand);
    return [
        'hands' => ['p1' => $p1Hand, 'p2' => $p2Hand],
        'initialSeen' => ['p1' => $p1Seen, 'p2' => $p2Seen],
        'deck' => array_values($cards),
    ];
}

function room_opponent(string $slot): string
{
    return $slot === 'p1' ? 'p2' : 'p1';
}

function room_mask_of(array $cardIndices): int
{
    $mask = 0;
    foreach ($cardIndices as $i) {
        $mask |= 1 << ((int)$i);
    }
    return $mask;
}

// room_state.php と room_action.php で共通の「役割ごとの見え方」を組み立てる。
// 相手の手札は絶対に含めない（ゲーム終了後を除く）。観戦者は両方見える。
function room_build_view(array $room, array $state, string $role): array
{
    $response = [
        'ok' => true,
        'roomCode' => $room['room_code'],
        'handSize' => (int)$room['hand_size'],
        'status' => $room['status'],
        'version' => (int)$room['version'],
        'role' => $role,
        'turn' => $state['turn'],
        'firstPlayer' => $state['firstPlayer'],
        'initialQuestionPending' => $state['initialQuestionPending'],
        'deckRemaining' => count($state['deck']),
        'askedNos' => [
            'p1' => array_map('intval', array_keys($state['asked']['p1'])),
            'p2' => array_map('intval', array_keys($state['asked']['p2'])),
        ],
        'log' => $state['log'],
        'over' => $state['over'],
        'reason' => $state['reason'],
        'winner' => $state['winner'],
    ];

    if ($role === 'spectator') {
        $response['hands'] = $state['hands'];
        $response['seen'] = $state['seen'];
        $response['initialSeen'] = $state['initialSeen'];
        $response['offers'] = $state['offers'];
        return $response;
    }

    $opponent = room_opponent($role);
    $response['yourHand'] = $state['hands'][$role];
    $response['yourSeen'] = $state['seen'][$role];
    $response['yourInitialSeen'] = $state['initialSeen'][$role];
    $response['opponentSeenCount'] = count($state['seen'][$opponent]);
    $response['yourOffer'] = $state['offers'][$role];
    if ($state['over']) {
        $response['opponentHand'] = $state['hands'][$opponent];
    }
    return $response;
}
