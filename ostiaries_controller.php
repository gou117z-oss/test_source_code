<?php
/**
 * Ostiaries Platform API — コントローラ
 * JavaScript からの Ajax リクエストを受け取り、JSON を返す
 *
 * エンドポイント (POST, Content-Type: application/json):
 *   action=start   — NewTransaction + StartAuthentication を実行
 *                    返り値: { transaction_id, dial_number }
 *   action=status  — GetTransactionStatus を実行
 *                    返り値: { status }
 *   action=result  — GetTransaction を実行（認証完了後）
 *                    返り値: { caller_number, auth_result, ... }
 *   action=cancel  — CancelAuthentication を実行
 *                    返り値: {}
 */

require_once __DIR__ . '/ostiaries_api.php';

// ============================================================
// 設定（実際の値に変更してください）
// ============================================================
define('OSTIARIES_API_KEY',    'YOUR_API_KEY');
define('OSTIARIES_ACCESS_KEY', 'YOUR_ACCESS_KEY');
define('OSTIARIES_SERVICE_ID', 'YOUR_SERVICE_ID');

// ============================================================
// リクエスト解析
// ============================================================

header('Content-Type: application/json; charset=UTF-8');

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

switch ($action) {

    // ----------------------------------------------------------
    case 'start':
    // ----------------------------------------------------------
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

        $result         = ostiaries_get_result($new_tx);
        $transaction_id = $result['transaction_id'];
        $dial_number    = $result['dial_number'];

        $start = ostiaries_start_authentication(
            OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
            OSTIARIES_SERVICE_ID, $transaction_id
        );
        if (!ostiaries_is_success($start)) {
            _json_api_error($start);
        }

        _json_ok([
            'transaction_id' => $transaction_id,
            'dial_number'    => $dial_number,
        ]);
        break;

    // ----------------------------------------------------------
    case 'status':
    // ----------------------------------------------------------
        $transaction_id = $input['transaction_id'] ?? '';
        if (empty($transaction_id)) {
            _json_error('transaction_id が不正です', 400);
        }

        $res = ostiaries_get_transaction_status(
            OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
            OSTIARIES_SERVICE_ID, $transaction_id
        );

        if ($res['_retry'] ?? false) {
            // 429: JS 側にリトライを促す
            http_response_code(429);
            echo json_encode(['retry' => true]);
            exit;
        }
        if (!ostiaries_is_success($res)) {
            _json_api_error($res);
        }

        $result = ostiaries_get_result($res);
        _json_ok(['status' => $result['status'] ?? '']);
        break;

    // ----------------------------------------------------------
    case 'result':
    // ----------------------------------------------------------
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
        break;

    // ----------------------------------------------------------
    case 'cancel':
    // ----------------------------------------------------------
        $transaction_id = $input['transaction_id'] ?? '';
        if (empty($transaction_id)) {
            _json_error('transaction_id が不正です', 400);
        }

        $res = ostiaries_cancel_authentication(
            OSTIARIES_API_KEY, OSTIARIES_ACCESS_KEY,
            OSTIARIES_SERVICE_ID, $transaction_id
        );
        if (!ostiaries_is_success($res)) {
            _json_api_error($res);
        }

        _json_ok([]);
        break;

    // ----------------------------------------------------------
    default:
    // ----------------------------------------------------------
        _json_error('action が不正です', 400);
}
