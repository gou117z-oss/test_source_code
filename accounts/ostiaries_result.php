<?php
/**
 * Ostiaries — GetTransaction
 * POST /accounts/ostiaries_result
 *
 * リクエスト JSON:
 *   transaction_id  string  必須
 *
 * レスポンス JSON:
 *   { result: 'ok', data: { caller_number, auth_result, ... } }
 */

ob_start();
error_reporting(0);

require_once __DIR__ . '/../ostiaries_api.php';
require_once __DIR__ . '/../ostiaries_config.php';

ob_clean();
header('Content-Type: application/json; charset=UTF-8');

$input          = json_decode(file_get_contents('php://input'), true) ?: [];
$transaction_id = $input['transaction_id'] ?? '';
if (empty($transaction_id)) {
    _json_error('transaction_id が不正です', 400);
}

$res = ostiaries_get_transaction(
    OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
    OSTIARIES_SERVICE_ID, $transaction_id
);
if (!ostiaries_is_success($res)) {
    _json_api_error($res);
}

_json_ok(ostiaries_get_result($res));
