<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 16384) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 16384) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_body']);
    exit;
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$allowedEvents = ['game_start', 'question_offer', 'question_selected', 'action', 'game_end'];
$event = is_string($input['event'] ?? null) ? $input['event'] : '';
if (!in_array($event, $allowedEvents, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_event']);
    exit;
}

function clean_string($value, int $maxLength = 160): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength, 'UTF-8')
        : substr($value, 0, $maxLength);
}

function clean_int($value, int $min = 0, int $max = 10000000): int
{
    if (!is_numeric($value)) {
        return 0;
    }
    return max($min, min($max, (int)$value));
}

function clean_list($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach (array_slice($value, 0, 16) as $item) {
        $clean = clean_string($item, 64);
        if ($clean !== '') {
            $out[] = $clean;
        }
    }
    return array_values(array_unique($out));
}

$record = [
    'serverTime' => gmdate('c'),
    'event' => $event,
    'sessionId' => clean_string($input['sessionId'] ?? '', 80),
    'gameId' => clean_string($input['gameId'] ?? '', 80),
    'handSize' => clean_int($input['handSize'] ?? 0, 0, 16),
];

$stringFields = [
    'actor' => 24,
    'offerId' => 80,
    'questionKey' => 64,
    'questionLabel' => 160,
    'variantId' => 64,
    'variantLabel' => 200,
    'action' => 32,
    'abandonedOfferId' => 80,
    'firstPlayer' => 8,
    'reason' => 32,
    'winner' => 16,
];
foreach ($stringFields as $field => $length) {
    if (array_key_exists($field, $input)) {
        $record[$field] = clean_string($input[$field], $length);
    }
}

$integerFields = ['level', 'initialCandidates', 'actionNumber', 'candidates', 'candidatesBefore', 'candidatesAfter', 'playerActions', 'aiActions'];
foreach ($integerFields as $field) {
    if (array_key_exists($field, $input)) {
        $record[$field] = clean_int($input[$field]);
    }
}

foreach (['initial', 'reciprocal'] as $field) {
    if (array_key_exists($field, $input)) {
        $record[$field] = (bool)$input[$field];
    }
}
if (array_key_exists('offered', $input)) {
    $record['offered'] = clean_list($input['offered']);
}

$config = require __DIR__ . '/question_analytics_config.php';
$logDir = (string)($config['log_dir'] ?? '');
if ($logDir === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'log_directory_not_configured']);
    exit;
}
if (!is_dir($logDir) && !mkdir($logDir, 0750, true) && !is_dir($logDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot_create_log_directory']);
    exit;
}

$file = $logDir . DIRECTORY_SEPARATOR . 'questions-' . gmdate('Y-m') . '.php';
$newFile = !file_exists($file);
$line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
// PHPとして直接開かれてもログ本文が配信されないよう、先頭で終了させます。
$write = ($newFile ? "<?php exit; ?>\n" : '') . $line;
if (file_put_contents($file, $write, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot_write_log']);
    exit;
}
if ($newFile) {
    @chmod($file, 0640);
}

http_response_code(202);
echo json_encode(['ok' => true]);
