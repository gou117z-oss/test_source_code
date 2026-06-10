<?php
/**
 * Ostiaries Platform API — レポートバック受信スクリプト
 * API Ver 3.0.8 / PHP 非オブジェクト指向
 *
 * このスクリプトを Web サーバに配置し、NewTransaction の
 * reportback_url にその URL を指定してください。
 *
 * 処理フロー:
 *   1. X-Ostiaries-Signature ヘッダの ES512 署名を検証
 *   2. ボディの SHA-512 ダイジェストを比較（改ざん検知）
 *   3. パラメータに応じたビジネスロジックを実行
 *   4. 200 OK を返して Ostiaries サーバに受信を通知
 */

require_once __DIR__ . '/ostiaries_api.php';

// ============================================================
// 受信・検証
// ============================================================

$params = ostiaries_receive_reportback();

if ($params === false) {
    // 署名検証失敗またはヘッダなし
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'signature verification failed']);
    exit;
}

// ============================================================
// パラメータ取得
// ============================================================

$transaction_id = $params['transaction_id'] ?? '';
$service_id     = $params['service_id']     ?? '';
$status         = $params['status']         ?? '';         // completed / failed / cancelled / expired
$auth_result    = $params['auth_result']    ?? '';         // ok / ng / unknown
$caller_number  = $params['caller_number']  ?? '';         // 発信者番号（completed 時のみ）
$identifier     = $params['identifier']     ?? '';         // NewTransaction で指定した任意識別子

// ============================================================
// ビジネスロジック（必要に応じて実装してください）
// ============================================================

if ($status === 'completed' && $auth_result === 'ok') {
    // 認証成功: DBのステータスを更新するなど
    _reportback_handle_success($transaction_id, $caller_number, $identifier);

} elseif ($status === 'completed' && $auth_result === 'ng') {
    // 発信者番号不一致
    _reportback_handle_ng($transaction_id, $identifier);

} else {
    // failed / cancelled / expired など
    _reportback_handle_other($transaction_id, $status, $identifier);
}

// ============================================================
// 200 OK を返す（必須）
// ============================================================

http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['result' => 'ok']);
exit;

// ============================================================
// ハンドラ関数（実装例）
// ============================================================

function _reportback_handle_success($transaction_id, $caller_number, $identifier)
{
    error_log("[ostiaries] 認証成功 transaction_id={$transaction_id} caller={$caller_number} id={$identifier}");

    // 例: DB の認証ステータスを「成功」に更新
    // $pdo = get_pdo();
    // $stmt = $pdo->prepare(
    //     "UPDATE auth_sessions SET status='ok', caller_number=? WHERE transaction_id=?"
    // );
    // $stmt->execute([$caller_number, $transaction_id]);
}

function _reportback_handle_ng($transaction_id, $identifier)
{
    error_log("[ostiaries] 番号不一致 transaction_id={$transaction_id} id={$identifier}");

    // 例: DB の認証ステータスを「NG」に更新
    // $pdo = get_pdo();
    // $stmt = $pdo->prepare(
    //     "UPDATE auth_sessions SET status='ng' WHERE transaction_id=?"
    // );
    // $stmt->execute([$transaction_id]);
}

function _reportback_handle_other($transaction_id, $status, $identifier)
{
    error_log("[ostiaries] 認証終了({$status}) transaction_id={$transaction_id} id={$identifier}");

    // 例: タイムアウト・キャンセル等の処理
    // $pdo = get_pdo();
    // $stmt = $pdo->prepare(
    //     "UPDATE auth_sessions SET status=? WHERE transaction_id=?"
    // );
    // $stmt->execute([$status, $transaction_id]);
}
