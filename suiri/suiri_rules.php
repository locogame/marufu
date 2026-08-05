<?php
declare(strict_types=1);

// suiri.html の makeQuestions() をサーバー権威判定用にPHP移植したもの。
// 質問セット（15枚＋⚡4種）を変更するときは、必ず suiri.html 側の makeQuestions() と
// この関数の両方を合わせて直すこと（言語が違うため自動同期はできない）。
//
// カード番号 0..15 = 動物index(0..3)*4 + 季節index(0..3)。
// 動物: 0=アヒル 1=クマ 2=リス 3=ウサギ
// 季節: 0=はる 1=なつ 2=あき 3=ふゆ

function suiri_type_mask(int $motifIdx): int
{
    $mask = 0;
    for ($s = 0; $s < 4; $s++) {
        $mask |= 1 << ($motifIdx * 4 + $s);
    }
    return $mask;
}

function suiri_season_mask(int $seasonIdx): int
{
    $mask = 0;
    for ($m = 0; $m < 4; $m++) {
        $mask |= 1 << ($m * 4 + $seasonIdx);
    }
    return $mask;
}

function suiri_cnt(int $hand, int $mask): int
{
    return substr_count(decbin($hand & $mask), '1');
}

function suiri_has(int $hand, int $cardIndex): bool
{
    return (($hand >> $cardIndex) & 1) === 1;
}

function suiri_animal_kinds(int $hand): int
{
    $kinds = 0;
    for ($m = 0; $m < 4; $m++) {
        if (suiri_cnt($hand, suiri_type_mask($m)) > 0) {
            $kinds++;
        }
    }
    return $kinds;
}

function suiri_season_kinds(int $hand): int
{
    $kinds = 0;
    for ($s = 0; $s < 4; $s++) {
        if (suiri_cnt($hand, suiri_season_mask($s)) > 0) {
            $kinds++;
        }
    }
    return $kinds;
}

// variantId から数字を抜き出す（例: "a2"→[2], "s0-3"→[0,3], "c15"→[15], "all"/"kinds"→[]）
function suiri_variant_ints(string $variantId): array
{
    preg_match_all('/\d+/', $variantId, $m);
    return array_map('intval', $m[0]);
}

/**
 * 質問キー＋variantIdに対する答えを、指定した手札マスクについて計算する。
 * 真偽値の質問は 0/1、数を答える質問（strong-*・kinds系）は整数を返す。
 */
function suiri_answer(string $key, string $variantId, int $hand): int
{
    $nums = suiri_variant_ints($variantId);

    switch ($key) {
        case 'animal-any':
            return suiri_cnt($hand, suiri_type_mask($nums[0])) > 0 ? 1 : 0;
        case 'season-any':
            return suiri_cnt($hand, suiri_season_mask($nums[0])) > 0 ? 1 : 0;

        case 'card-present':
            return suiri_has($hand, $nums[0]) ? 1 : 0;

        case 'animal-all':
            return suiri_animal_kinds($hand) === 4 ? 1 : 0;
        case 'season-all':
            return suiri_season_kinds($hand) === 4 ? 1 : 0;

        case 'animal-same':
            return suiri_cnt($hand, suiri_type_mask($nums[0])) === suiri_cnt($hand, suiri_type_mask($nums[1])) ? 1 : 0;
        case 'season-same':
            return suiri_cnt($hand, suiri_season_mask($nums[0])) === suiri_cnt($hand, suiri_season_mask($nums[1])) ? 1 : 0;

        case 'animal-most': {
            $counts = [];
            for ($m = 0; $m < 4; $m++) {
                $counts[$m] = suiri_cnt($hand, suiri_type_mask($m));
            }
            return $counts[$nums[0]] === max($counts) ? 1 : 0;
        }
        case 'season-most': {
            $counts = [];
            for ($s = 0; $s < 4; $s++) {
                $counts[$s] = suiri_cnt($hand, suiri_season_mask($s));
            }
            return $counts[$nums[0]] === max($counts) ? 1 : 0;
        }

        case 'animal-more':
            return suiri_cnt($hand, suiri_type_mask($nums[0])) > suiri_cnt($hand, suiri_type_mask($nums[1])) ? 1 : 0;
        case 'season-more':
            return suiri_cnt($hand, suiri_season_mask($nums[0])) > suiri_cnt($hand, suiri_season_mask($nums[1])) ? 1 : 0;

        case 'animal-odd':
            return suiri_cnt($hand, suiri_type_mask($nums[0]) | suiri_type_mask($nums[1])) % 2;
        case 'season-odd':
            return suiri_cnt($hand, suiri_season_mask($nums[0]) | suiri_season_mask($nums[1])) % 2;

        case 'animal-one':
            return suiri_cnt($hand, suiri_type_mask($nums[0])) === 1 ? 1 : 0;
        case 'season-one':
            return suiri_cnt($hand, suiri_season_mask($nums[0])) === 1 ? 1 : 0;

        case 'strong-animal-count':
            return suiri_cnt($hand, suiri_type_mask($nums[0]));
        case 'strong-season-count':
            return suiri_cnt($hand, suiri_season_mask($nums[0]));
        case 'strong-animal-kinds':
            return suiri_animal_kinds($hand);
        case 'strong-season-kinds':
            return suiri_season_kinds($hand);

        default:
            throw new InvalidArgumentException("unknown question key: $key");
    }
}

// 通常デッキ15枚の定義（no => key）。強い⚡系(101-104)は別枠なので含めない。
function suiri_normal_cards(): array
{
    return [
        1 => 'animal-any',
        2 => 'season-any',
        3 => 'card-present',
        4 => 'animal-all',
        5 => 'season-all',
        6 => 'animal-same',
        7 => 'season-same',
        8 => 'animal-most',
        9 => 'season-most',
        10 => 'animal-more',
        11 => 'season-more',
        12 => 'animal-odd',
        13 => 'season-odd',
        14 => 'animal-one',
        15 => 'season-one',
    ];
}

// ⚡相互回答の別枠4種（先手の最初の1回だけ使える）。
function suiri_initial_cards(): array
{
    return [
        101 => 'strong-animal-count',
        102 => 'strong-season-count',
        103 => 'strong-animal-kinds',
        104 => 'strong-season-kinds',
    ];
}

// key に対して、有効なvariantId一覧を返す（サーバー側でのバリデーション用）。
function suiri_variants_for(string $key): array
{
    $pairs = [];
    for ($a = 0; $a < 4; $a++) {
        for ($b = $a + 1; $b < 4; $b++) {
            $pairs[] = [$a, $b];
        }
    }
    $ordered = [];
    for ($a = 0; $a < 4; $a++) {
        for ($b = 0; $b < 4; $b++) {
            if ($a !== $b) {
                $ordered[] = [$a, $b];
            }
        }
    }
    $oddPairs = [[0, 1], [0, 2], [0, 3]];

    switch ($key) {
        case 'animal-any':
        case 'animal-one':
        case 'animal-most':
        case 'strong-animal-count':
            return ['a0', 'a1', 'a2', 'a3'];
        case 'season-any':
        case 'season-one':
        case 'season-most':
        case 'strong-season-count':
            return ['s0', 's1', 's2', 's3'];
        case 'card-present':
            return array_map(fn($c) => "c$c", range(0, 15));
        case 'animal-all':
        case 'season-all':
            return ['all'];
        case 'strong-animal-kinds':
        case 'strong-season-kinds':
            return ['kinds'];
        case 'animal-same':
            return array_map(fn($p) => "a{$p[0]}-{$p[1]}", $pairs);
        case 'season-same':
            return array_map(fn($p) => "s{$p[0]}-{$p[1]}", $pairs);
        case 'animal-more':
            return array_map(fn($p) => "a{$p[0]}-{$p[1]}", $ordered);
        case 'season-more':
            return array_map(fn($p) => "s{$p[0]}-{$p[1]}", $ordered);
        case 'animal-odd':
            return array_map(fn($p) => "a{$p[0]}-{$p[1]}", $oddPairs);
        case 'season-odd':
            return array_map(fn($p) => "s{$p[0]}-{$p[1]}", $oddPairs);
        default:
            return [];
    }
}
