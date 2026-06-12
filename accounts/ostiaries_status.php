<?php
/**
 * Ostiaries — GetTransactionStatus
 * POST /accounts/ostiaries_status
 *
 * リクエスト JSON:
 *   transaction_id  string  必須
 *
 * レスポンス JSON:
 *   { result: 'ok', data: { status } }
 */

require_once __DIR__ . '/../ostiaries_api.php';
require_once __DIR__ . '/../ostiaries_config.php';

header('Content-Type: application/json; charset=UTF-8');

$input          = json_decode(file_get_contents('php://input'), true) ?: [];
$transaction_id = $input['transaction_id'] ?? '';
if (empty($transaction_id)) {
    _json_error('transaction_id が不正です', 400);
}

$res = ostiaries_get_transaction_status(
    OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
    OSTIARIES_SERVICE_ID, $transaction_id
);

if ($res['_retry'] ?? false) {
    http_response_code(429);
    echo json_encode(['retry' => true]);
    exit;
}
if (!ostiaries_is_success($res)) {
    _json_api_error($res);
}

$result = ostiaries_get_result($res);
_json_ok(['status' => $result['status'] ?? '']);
