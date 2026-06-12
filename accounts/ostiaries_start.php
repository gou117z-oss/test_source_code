<?php
/**
 * Ostiaries — NewTransaction + StartAuthentication
 * POST /accounts/ostiaries_start
 *
 * リクエスト JSON:
 *   customer_numbers  array   必須
 *   wait_time         int     任意
 *   identifier        string  任意
 *   reportback_url    string  任意
 *
 * レスポンス JSON:
 *   { result: 'ok', data: { transaction_id, dial_number } }
 */

ob_start();
error_reporting(0);

require_once __DIR__ . '/../ostiaries_api.php';
require_once __DIR__ . '/../ostiaries_config.php';

ob_clean();
header('Content-Type: application/json; charset=UTF-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$customer_numbers = $input['customer_numbers'] ?? [];
if (empty($customer_numbers) || !is_array($customer_numbers)) {
    _json_error('customer_numbers が不正です', 400);
}

$options = [];
if (isset($input['wait_time']))      { $options['wait_time']      = (int)$input['wait_time']; }
if (isset($input['identifier']))     { $options['identifier']     = (string)$input['identifier']; }
if (isset($input['reportback_url'])) { $options['reportback_url'] = (string)$input['reportback_url']; }

$new_tx = ostiaries_new_transaction(
    OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
    OSTIARIES_SERVICE_ID, $customer_numbers, $options
);
if (!ostiaries_is_success($new_tx)) {
    _json_api_error($new_tx);
}

$result          = ostiaries_get_result($new_tx);
$transaction_id  = $result['transaction_id'];
$authentic_number = $result['authentic_number'];

$start = ostiaries_start_authentication(
    OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
    OSTIARIES_SERVICE_ID, $transaction_id
);
if (!ostiaries_is_success($start)) {
    _json_api_error($start);
}

$expires_at = $result['expires_at'] ?? null;
$expires_in = $expires_at
    ? max(0, (int)(strtotime($expires_at) - time()))
    : 120;   // 取得できない場合は 120 秒をデフォルトにする

_json_ok([
    'transaction_id'   => $transaction_id,
    'authentic_number' => $authentic_number,
    'expires_at'       => $expires_at,
    'expires_in'       => $expires_in,
]);
