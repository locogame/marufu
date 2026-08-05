<?php
declare(strict_types=1);

return [
    // 可能なら公開フォルダの外側の絶対パスに変更してください。
    'log_dir' => getenv('QUESTION_LOG_DIR') ?: __DIR__ . '/question-logs',

    // アップロード前に、長く推測されにくい文字列へ必ず変更してください。
    // サーバーで環境変数を設定できる場合は QUESTION_REPORT_PASSWORD を優先します。
    'report_password' => getenv('QUESTION_REPORT_PASSWORD') ?: 'CHANGE_THIS_TO_A_LONG_RANDOM_PASSWORD',
];
