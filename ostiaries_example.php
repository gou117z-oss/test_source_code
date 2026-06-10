<?php
/**
 * Ostiaries Platform API 使用例
 * API Ver 3.0.8 / PHP 非オブジェクト指向
 *
 * フロー:
 *   1. NewTransaction  — トランザクション生成
 *   2. StartAuthentication — 着信認証開始
 *   3. GetTransactionStatus — 認証結果をポーリングで確認
 *   4. GetTransaction  — 認証完了後に詳細情報取得
 */

require_once __DIR__ . '/ostiaries_api.php';

// ============================================================
// 設定（実際の値に変更してください）
// ============================================================
$API_KEY    = 'YOUR_API_KEY';      // 管理ツールで発行した API キー
$ACCESS_KEY = 'YOUR_ACCESS_KEY';   // 管理ツールで発行したアクセスキー
$SERVICE_ID = 'YOUR_SERVICE_ID';   // 管理ツールで確認したサービス ID

// ============================================================
// Step 1: NewTransaction — トランザクション生成
// ============================================================

// 認証させたい顧客の電話番号（市外局番なし・ハイフンなし / E.164 形式いずれも仕様に従う）
$customer_numbers = ['09012345678'];

$options = [
    'wait_time'      => 120,                                  // 着信待ち秒数
    'identifier'     => 'order_' . uniqid(),                  // CP 側の任意識別子
    'reportback_url' => 'https://example.com/ostiaries_reportback.php', // レポートバック URL
];

echo "=== NewTransaction ===\n";
$new_tx = ostiaries_new_transaction($API_KEY, $ACCESS_KEY, $SERVICE_ID, $customer_numbers, $options);

if (!ostiaries_is_success($new_tx)) {
    fprintf(STDERR,
        "NewTransaction 失敗: [%s] %s\n",
        ostiaries_get_error_code($new_tx),
        ostiaries_get_error_message($new_tx)
    );
    exit(1);
}

$result         = ostiaries_get_result($new_tx);
$transaction_id = $result['transaction_id'];
$dial_number    = $result['dial_number'];   // ユーザーに表示する着信専用番号

echo "transaction_id : {$transaction_id}\n";
echo "dial_number    : {$dial_number}\n\n";

// ============================================================
// Step 2: StartAuthentication — 着信認証開始
// ============================================================

echo "=== StartAuthentication ===\n";
$start = ostiaries_start_authentication($API_KEY, $ACCESS_KEY, $SERVICE_ID, $transaction_id);

if (!ostiaries_is_success($start)) {
    fprintf(STDERR,
        "StartAuthentication 失敗: [%s] %s\n",
        ostiaries_get_error_code($start),
        ostiaries_get_error_message($start)
    );
    exit(1);
}

echo "認証開始 OK\n";
echo "ユーザーに「{$dial_number}」へ電話するよう案内してください。\n\n";

// ============================================================
// Step 3: GetTransactionStatus — 結果ポーリング
// ============================================================

echo "=== GetTransactionStatus (ポーリング) ===\n";

$max_polls   = 60;   // 最大ポーリング回数
$poll_sec    = 3;    // ポーリング間隔（秒）
$final_status = null;

for ($i = 0; $i < $max_polls; $i++) {
    sleep($poll_sec);

    $status_res = ostiaries_get_transaction_status($API_KEY, $ACCESS_KEY, $SERVICE_ID, $transaction_id);

    if (!ostiaries_is_success($status_res)) {
        // 429 は同時実行数超過 → 少し待ってリトライ
        if (($status_res['_retry'] ?? false)) {
            echo "429 Too Many Requests — リトライします...\n";
            continue;
        }
        fprintf(STDERR,
            "GetTransactionStatus 失敗: [%s] %s\n",
            ostiaries_get_error_code($status_res),
            ostiaries_get_error_message($status_res)
        );
        exit(1);
    }

    $status_result = ostiaries_get_result($status_res);
    $status        = $status_result['status'] ?? '';
    echo "[poll {$i}] status={$status}\n";

    // status が終端状態になったらポーリング終了
    // 仕様上の終端: completed / failed / cancelled / expired
    if (in_array($status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
        $final_status = $status;
        break;
    }
}

if ($final_status === null) {
    fwrite(STDERR, "タイムアウト: ポーリング上限に達しました。\n");
    exit(1);
}

echo "\n最終ステータス: {$final_status}\n\n";

// ============================================================
// Step 4: GetTransaction — 詳細情報取得（認証成功時）
// ============================================================

if ($final_status === 'completed') {
    echo "=== GetTransaction ===\n";
    $tx = ostiaries_get_transaction($API_KEY, $ACCESS_KEY, $SERVICE_ID, $transaction_id);

    if (ostiaries_is_success($tx)) {
        $detail = ostiaries_get_result($tx);
        echo "caller_number  : " . ($detail['caller_number']  ?? '—') . "\n";
        echo "auth_result    : " . ($detail['auth_result']    ?? '—') . "\n";
        echo "created_at     : " . ($detail['created_at']     ?? '—') . "\n";
        echo "completed_at   : " . ($detail['completed_at']   ?? '—') . "\n";
    } else {
        fprintf(STDERR,
            "GetTransaction 失敗: [%s] %s\n",
            ostiaries_get_error_code($tx),
            ostiaries_get_error_message($tx)
        );
    }
} else {
    echo "認証は完了しませんでした（status={$final_status}）\n";

    // 必要であれば CancelAuthentication でキャンセル
    // $cancel = ostiaries_cancel_authentication($API_KEY, $ACCESS_KEY, $SERVICE_ID, $transaction_id);
}
