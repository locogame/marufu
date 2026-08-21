<?php
declare(strict_types=1);

$config = require __DIR__ . '/question_analytics_config.php';
$reportPassword = (string)($config['report_password'] ?? '');
$logDir = (string)($config['log_dir'] ?? '');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if ($reportPassword === '' || $reportPassword === 'CHANGE_THIS_TO_A_LONG_RANDOM_PASSWORD') {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>設定が必要です</title><p>question_analytics_config.php の report_password を変更してから開いてください。</p>';
    exit;
}

session_name('marufu_question_report');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: question_report.php');
    exit;
}

$loginError = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $given = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (hash_equals($reportPassword, $given)) {
        $_SESSION['question_report_authenticated'] = true;
        header('Location: question_report.php');
        exit;
    }
    $loginError = true;
}

if (empty($_SESSION['question_report_authenticated'])) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>質問ログ</title>
<style>body{font-family:system-ui,sans-serif;background:#f6eee3;color:#3d2d28;display:grid;place-items:center;min-height:100vh;margin:0}.box{width:min(88vw,380px);background:#fff;padding:28px;border-radius:18px;box-shadow:0 8px 30px #6d4c3722}h1{font-size:1.25rem;margin:0 0 18px}label{font-size:.85rem;font-weight:700}input{box-sizing:border-box;width:100%;font:inherit;padding:11px;border:1px solid #cfbeb1;border-radius:9px;margin:7px 0 12px}button{width:100%;border:0;border-radius:9px;padding:11px;background:#c9577f;color:#fff;font-weight:800}.error{color:#b6324f;font-size:.8rem}</style></head>
<body><form class="box" method="post"><h1>🔎 質問ログ集計</h1><label for="password">レポート用パスワード</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><?php if ($loginError): ?><p class="error">パスワードが違います。</p><?php endif; ?><button type="submit">集計を見る</button></form></body></html>
    <?php
    exit;
}

$handFilter = (string)($_GET['hand'] ?? 'all');
if (!in_array($handFilter, ['all', '3', '4'], true)) {
    $handFilter = 'all';
}

$questionNames = [
    // 🌱 やさしい面（表）
    'animal-any' => '🌱（動物）はいる？',
    'season-any' => '🌱（季節）はある？',
    'animal-one' => '🌱（動物）はちょうど1匹？',
    'season-one' => '🌱（季節）はちょうど1枚？',
    'adj-both' => '🌱 となりあう季節を どっちも持ってる？',
    'opp-both' => '🌱 向かいあう季節を どっちも持ってる？',
    'match-any' => '🌱 ぴったりカードを持ってる？',
    'match-which' => '🌱（動物）の ぴったりカードはある？',
    'animal-most' => '🌱（動物）が一番多い？',
    'season-most' => '🌱（季節）が一番多い？',
    'animal-kinds' => '🌱 動物は何種類いる？',
    'season-kinds' => '🌱 季節は何種類ある？',
    'animal-more' => '🌱（動物A）は（動物B）より多い？',
    'animal-same' => '🌱（動物A）と（動物B）は同じ数？',
    'animal-both' => '🌱（動物A）と（動物B）はどっちもいる？',
    // 🔥 上級者面（裏）
    'animal-even' => '🔥（動物）は偶数匹？',
    'season-even' => '🔥（季節）は偶数枚？',
    'animal-odd' => '🔥（動物A）＋（動物B）は奇数匹？',
    'season-odd' => '🔥（季節A）＋（季節B）は奇数枚？',
    'adj-xor' => '🔥 となりあう季節、あるのは片方だけ？',
    'opp-xor' => '🔥 向かいあう季節、あるのは片方だけ？',
    'match-even' => '🔥 ぴったりカードは偶数枚？',
    'match-more' => '🔥（動物）は じぶんの季節より多い？',
    'cross-same' => '🔥（動物）と（季節）は同じ枚数？',
    'cross-both' => '🔥（動物）がいて（季節）もある？',
    'kinds-same' => '🔥 動物と季節、種類の数は同じ？',
    'season-kinds-even' => '🔥 季節の種類数は偶数？',
    'animal-none-but' => '🔥（動物A）はいなくて（動物B）はいる？',
    'cross-xor' => '🔥（動物）と（季節）、あるのは片方だけ？',
    'animal-xor' => '🔥（動物A）と（動物B）、いるのは片方だけ？',
    'card-common' => '🌱🎴 自分の1まいを見せて、共通の要素は何まい？',
    // ⚡ 相互回答（両面カードとは別枠）
    'strong-animal-count' => '⚡（動物）は何匹？',
    'strong-season-count' => '⚡（季節）は何枚？',
];

$templates = [];
foreach ($questionNames as $key => $name) {
    $templates[$key] = ['key' => $key, 'name' => $name, 'offered' => 0, 'selected' => 0, 'gainSum' => 0.0, 'gainCount' => 0];
}
$variants = [];
$offerIds = [];
$selectedOfferIds = [];
$games = 0;
$completed = 0;
$humanWins = 0;
$aiWins = 0;
$quits = 0;
$questionActions = 0;
$peekActions = 0;
$guessActions = 0;
$latestTime = '';

$files = [];
if (is_dir($logDir)) {
    $files = array_merge(
        glob($logDir . DIRECTORY_SEPARATOR . 'questions-*.php') ?: [],
        glob($logDir . DIRECTORY_SEPARATOR . 'questions-*.jsonl') ?: []
    );
}
sort($files);
foreach ($files as $file) {
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        continue;
    }
    while (($line = fgets($handle)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $handSize = (int)($row['handSize'] ?? 0);
        if ($handFilter !== 'all' && $handSize !== (int)$handFilter) {
            continue;
        }
        $time = is_string($row['serverTime'] ?? null) ? $row['serverTime'] : '';
        if ($time > $latestTime) {
            $latestTime = $time;
        }
        $event = $row['event'] ?? '';
        if ($event === 'game_start') {
            $games++;
        } elseif ($event === 'question_offer' && ($row['actor'] ?? '') === 'human') {
            $offerId = is_string($row['offerId'] ?? null) ? $row['offerId'] : '';
            if ($offerId !== '') {
                $offerIds[$offerId] = true;
            }
            $offered = is_array($row['offered'] ?? null) ? array_unique($row['offered']) : [];
            foreach ($offered as $key) {
                if (!is_string($key)) {
                    continue;
                }
                if (!isset($templates[$key])) {
                    $templates[$key] = ['key' => $key, 'name' => $key, 'offered' => 0, 'selected' => 0, 'gainSum' => 0.0, 'gainCount' => 0];
                }
                $templates[$key]['offered']++;
            }
        } elseif ($event === 'question_selected' && ($row['actor'] ?? '') === 'human') {
            $questionActions++;
            $offerId = is_string($row['offerId'] ?? null) ? $row['offerId'] : '';
            if ($offerId !== '') {
                $selectedOfferIds[$offerId] = true;
            }
            $key = is_string($row['questionKey'] ?? null) ? $row['questionKey'] : '';
            if ($key === '') {
                continue;
            }
            if (!isset($templates[$key])) {
                $templates[$key] = ['key' => $key, 'name' => $key, 'offered' => 0, 'selected' => 0, 'gainSum' => 0.0, 'gainCount' => 0];
            }
            $templates[$key]['selected']++;
            $before = (int)($row['candidatesBefore'] ?? 0);
            $after = (int)($row['candidatesAfter'] ?? 0);
            if ($before > 0 && $after >= 0 && $after <= $before) {
                $gain = (($before - $after) / $before) * 100;
                $templates[$key]['gainSum'] += $gain;
                $templates[$key]['gainCount']++;
            }
            $variantId = is_string($row['variantId'] ?? null) ? $row['variantId'] : '';
            $variantLabel = is_string($row['variantLabel'] ?? null) ? $row['variantLabel'] : $variantId;
            $variantKey = $key . '::' . $variantId;
            if (!isset($variants[$variantKey])) {
                $variants[$variantKey] = ['question' => $templates[$key]['name'], 'label' => $variantLabel, 'selected' => 0, 'gainSum' => 0.0, 'gainCount' => 0];
            }
            $variants[$variantKey]['selected']++;
            if (isset($gain)) {
                $variants[$variantKey]['gainSum'] += $gain;
                $variants[$variantKey]['gainCount']++;
                unset($gain);
            }
        } elseif ($event === 'action' && ($row['actor'] ?? '') === 'human') {
            if (($row['action'] ?? '') === 'peek') {
                $peekActions++;
            } elseif (($row['action'] ?? '') === 'guess') {
                $guessActions++;
            }
        } elseif ($event === 'game_end') {
            if (($row['reason'] ?? '') === 'quit') {
                $quits++;
            } else {
                $completed++;
                if (($row['winner'] ?? '') === 'human') {
                    $humanWins++;
                } elseif (($row['winner'] ?? '') === 'ai') {
                    $aiWins++;
                }
            }
        }
    }
    fclose($handle);
}

$templateRows = array_values($templates);
foreach ($templateRows as &$row) {
    $row['rate'] = $row['offered'] > 0 ? ($row['selected'] / $row['offered']) * 100 : null;
    $row['gain'] = $row['gainCount'] > 0 ? $row['gainSum'] / $row['gainCount'] : null;
}
unset($row);
usort($templateRows, static function (array $a, array $b): int {
    $aRate = $a['rate'] ?? -1;
    $bRate = $b['rate'] ?? -1;
    return $bRate <=> $aRate ?: $b['offered'] <=> $a['offered'];
});

$observedRows = array_values(array_filter($templateRows, static fn(array $row): bool => $row['offered'] > 0));
$popular = array_slice($observedRows, 0, 3);
$rare = $observedRows;
usort($rare, static fn(array $a, array $b): int => ($a['rate'] ?? 0) <=> ($b['rate'] ?? 0) ?: $b['offered'] <=> $a['offered']);
$rare = array_slice($rare, 0, 3);

$variantRows = array_values($variants);
usort($variantRows, static fn(array $a, array $b): int => $b['selected'] <=> $a['selected']);
$abandonedOffers = count(array_diff_key($offerIds, $selectedOfferIds));
$totalActions = $questionActions + $peekActions + $guessActions;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function percent($value): string
{
    return $value === null ? '—' : number_format((float)$value, 1) . '%';
}

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="question-summary-' . gmdate('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['質問キー', '質問', '表示回数', '選択回数', '選択率', '平均候補削減率']);
    foreach ($templateRows as $row) {
        fputcsv($out, [$row['key'], $row['name'], $row['offered'], $row['selected'], percent($row['rate']), percent($row['gain'])]);
    }
    fclose($out);
    exit;
}

$filterLabel = $handFilter === 'all' ? '3枚＋4枚' : $handFilter . '枚版';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>質問ログ集計</title>
<style>
:root{--ink:#3d2d28;--muted:#806f66;--paper:#fff;--line:#e4d7cc;--pink:#c9577f;--green:#318c78}*{box-sizing:border-box}body{margin:0;background:#f6eee3;color:var(--ink);font-family:system-ui,-apple-system,sans-serif}main{width:min(1180px,calc(100% - 28px));margin:24px auto 60px}header{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px}h1{font-size:1.45rem;margin:0}.sub{font-size:.78rem;color:var(--muted);margin-top:4px}.toplinks{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}a,.filter button{color:#765c4e;background:#fff;border:1px solid var(--line);border-radius:9px;padding:7px 10px;text-decoration:none;font-size:.75rem;font-weight:700}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:13px 0}.card{background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:14px;box-shadow:0 2px 8px #51341f0c}.card b{display:block;font-size:1.4rem;color:var(--pink);margin-top:4px}.card span{font-size:.72rem;color:var(--muted)}.filter{display:flex;gap:7px;margin:16px 0}.filter a.active{background:#785c4d;color:#fff;border-color:#785c4d}.highlow{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:13px 0}.highlow h2,section>h2{font-size:1rem;margin:0 0 10px}.highlow ol{margin:0;padding-left:24px}.highlow li{padding:5px 2px;font-size:.82rem}.highlow em{font-style:normal;color:var(--pink);font-weight:800}.tablebox{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;margin-top:12px;overflow:auto}table{border-collapse:collapse;width:100%;font-size:.78rem;white-space:nowrap}th,td{text-align:right;border-bottom:1px solid #eee3da;padding:9px 8px}th{font-size:.68rem;color:var(--muted);position:sticky;top:0;background:#fff}th:first-child,td:first-child{text-align:left;white-space:normal;min-width:220px}.rate{font-weight:800;color:var(--pink)}.gain{color:var(--green);font-weight:700}.note{font-size:.72rem;line-height:1.6;color:var(--muted);margin:9px 2px}.empty{color:var(--muted);font-size:.82rem;padding:12px 0}@media(max-width:760px){main{width:min(100% - 18px,1180px);margin-top:14px}header{display:block}.toplinks{margin-top:10px;justify-content:flex-start}.cards{grid-template-columns:repeat(2,1fr)}.highlow{grid-template-columns:1fr}th:first-child,td:first-child{min-width:180px}}
</style>
</head>
<body><main>
<header><div><h1>🔎 質問ログ集計</h1><div class="sub"><?= h($filterLabel) ?> ／ 最終ログ <?= h($latestTime !== '' ? $latestTime : 'まだありません') ?></div></div><div class="toplinks"><a href="?hand=<?= h($handFilter) ?>&amp;format=csv">CSV保存</a><a href="?logout=1">ログアウト</a></div></header>

<nav class="filter"><a href="?hand=all" class="<?= $handFilter === 'all' ? 'active' : '' ?>">すべて</a><a href="?hand=3" class="<?= $handFilter === '3' ? 'active' : '' ?>">3枚版</a><a href="?hand=4" class="<?= $handFilter === '4' ? 'active' : '' ?>">4枚版</a></nav>

<div class="cards"><div class="card"><span>開始ゲーム</span><b><?= $games ?></b></div><div class="card"><span>完了ゲーム</span><b><?= $completed ?></b></div><div class="card"><span>人間の勝率</span><b><?= $completed ? number_format(($humanWins / $completed) * 100, 1) . '%' : '—' ?></b></div><div class="card"><span>質問を選択</span><b><?= $questionActions ?></b></div><div class="card"><span>質問を見て未選択</span><b><?= $abandonedOffers ?></b></div></div>

<div class="highlow">
<section class="card"><h2>よく選ばれる質問</h2><?php if ($popular): ?><ol><?php foreach ($popular as $row): ?><li><?= h($row['name']) ?> <em><?= h(percent($row['rate'])) ?></em> <small>(<?= $row['selected'] ?>/<?= $row['offered'] ?>)</small></li><?php endforeach; ?></ol><?php else: ?><p class="empty">まだデータがありません。</p><?php endif; ?></section>
<section class="card"><h2>あまり選ばれない質問</h2><?php if ($rare): ?><ol><?php foreach ($rare as $row): ?><li><?= h($row['name']) ?> <em><?= h(percent($row['rate'])) ?></em> <small>(<?= $row['selected'] ?>/<?= $row['offered'] ?>)</small></li><?php endforeach; ?></ol><?php else: ?><p class="empty">まだデータがありません。</p><?php endif; ?></section>
</div>
<p class="note">選択率＝選択回数÷3択（初回は2択）に表示された回数。サンプルが少ない質問は数値がぶれるため、表示回数も一緒に見てください。</p>

<section class="tablebox"><h2>質問カード別</h2><table><thead><tr><th>質問</th><th>表示</th><th>選択</th><th>選択率</th><th>平均候補削減率</th></tr></thead><tbody><?php foreach ($templateRows as $row): ?><tr><td><?= h($row['name']) ?><br><small><?= h($row['key']) ?></small></td><td><?= $row['offered'] ?></td><td><?= $row['selected'] ?></td><td class="rate"><?= h(percent($row['rate'])) ?></td><td class="gain"><?= h(percent($row['gain'])) ?></td></tr><?php endforeach; ?></tbody></table></section>

<section class="tablebox"><h2>質問の中身別</h2><?php if ($variantRows): ?><table><thead><tr><th>実際に聞いた質問</th><th>カード種類</th><th>選択</th><th>平均候補削減率</th></tr></thead><tbody><?php foreach ($variantRows as $row): ?><tr><td><?= h($row['label']) ?></td><td><?= h($row['question']) ?></td><td><?= $row['selected'] ?></td><td class="gain"><?= h(percent($row['gainCount'] ? $row['gainSum'] / $row['gainCount'] : null)) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="empty">まだ質問の選択データがありません。</p><?php endif; ?></section>

<section class="tablebox"><h2>プレイヤーの行動</h2><table><thead><tr><th>行動</th><th>回数</th><th>全行動に占める割合</th></tr></thead><tbody><tr><td>質問</td><td><?= $questionActions ?></td><td><?= h(percent($totalActions ? ($questionActions / $totalActions) * 100 : null)) ?></td></tr><tr><td>山札を見る</td><td><?= $peekActions ?></td><td><?= h(percent($totalActions ? ($peekActions / $totalActions) * 100 : null)) ?></td></tr><tr><td>予想を宣言</td><td><?= $guessActions ?></td><td><?= h(percent($totalActions ? ($guessActions / $totalActions) * 100 : null)) ?></td></tr></tbody></table><p class="note">完了 <?= $completed ?>ゲーム（人間 <?= $humanWins ?>勝／AI <?= $aiWins ?>勝）、途中終了 <?= $quits ?>ゲーム。</p></section>
</main></body></html>
