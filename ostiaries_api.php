<?php
/**
 * Ostiaries Platform API クライアント
 * API Ver 3.0.8 対応 / PHP 非オブジェクト指向
 */

define('OSTIARIES_API_BASE', 'https://api.ostiaries.net/3.0/');

// ============================================================
// 内部ヘルパー
// ============================================================

/**
 * Base64URL エンコード（JWS用）
 */
function _ostiaries_base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64URL デコード（JWS用）
 */
function _ostiaries_base64url_decode($data)
{
    $pad  = strlen($data) % 4;
    $data = strtr($data, '-_', '+/');
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data);
}

/**
 * JSON エンコード（スラッシュ・Unicode エスケープなし）
 */
function _ostiaries_json_encode($data)
{
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * JWS 署名を生成する（HS512）
 * 5.2. APIリクエスト時の署名 に対応
 */
function _ostiaries_generate_jws($body, $access_key)
{
    // リクエストボディから SHA-512 ダイジェストを生成
    $digest = _ostiaries_base64url_encode(hash('sha512', $body, true));

    // JWS ヘッダ・ペイロードを生成（キー順序は仕様に従う）
    $header  = _ostiaries_json_encode(['alg' => 'HS512', 'typ' => 'JWT']);
    $payload = _ostiaries_json_encode(['digest' => $digest]);

    $segments = [
        _ostiaries_base64url_encode($header),
        _ostiaries_base64url_encode($payload),
    ];

    // HMAC-SHA512 で署名
    $signing_input = implode('.', $segments);
    $signature     = hash_hmac('sha512', $signing_input, $access_key, true);
    $segments[]    = _ostiaries_base64url_encode($signature);

    return implode('.', $segments);
}

/**
 * Ostiaries サービス API へ POST リクエストを送信する
 *
 * @param  string      $command    API コマンド名
 * @param  array       $params     リクエストパラメータ
 * @param  string      $api_key    x-api-key ヘッダの値
 * @param  string|null $access_key 署名用アクセスキー（null の場合は署名なし）
 * @return array       デコード済みレスポンス
 */
function ostiaries_request($command, array $params, $api_key, $access_key = null)
{
    $url  = OSTIARIES_API_BASE . $command;
    $body = _ostiaries_json_encode($params);

    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'Content-Length: ' . strlen($body),
        'x-api-key: ' . $api_key,
    ];

    if ($access_key !== null) {
        $jws      = _ostiaries_generate_jws($body, $access_key);
        $headers[] = 'X-Ostiaries-Signature: ' . $jws;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,   // 4xx/5xx でも body を読む
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        return ['_error' => 'HTTP request failed (network error)'];
    }

    // HTTP ステータスコード取得
    $http_code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $http_code = (int)$m[1];
            }
        }
    }

    // 429: 同時実行数超過 → リトライ可能
    if ($http_code === 429) {
        return ['_error' => 'Too many requests (HTTP 429). Retry later.', '_retry' => true];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['_error' => 'JSON decode failed', '_raw' => $raw];
    }

    return $decoded;
}

// ============================================================
// サービス API
// ============================================================

/**
 * NewTransaction — トランザクション生成
 *
 * @param  string $api_key         x-api-key
 * @param  string $access_key      署名用アクセスキー
 * @param  string $service_id      サービス ID（管理ツール参照）
 * @param  array  $customer_numbers 顧客電話番号の配列（最大 16件）
 * @param  array  $options         任意オプション:
 *                                   'wait_time'      int    着信待ち秒数
 *                                   'identifier'     string CP 任意の識別子
 *                                   'answerbacks'    array  着信応答オブジェクトの配列
 *                                     [ ['method'=>'busy'|'voice'|'sound', 'message'=>'...'] ]
 *                                   'reportback_url' string レポートバック URL
 * @return array  レスポンス
 */
function ostiaries_new_transaction($api_key, $access_key, $service_id, array $customer_numbers, array $options = [])
{
    $params = [
        'service_id'       => $service_id,
        'customer_numbers' => $customer_numbers,
    ];

    if (isset($options['wait_time'])) {
        $params['wait_time'] = (int)$options['wait_time'];
    }
    if (isset($options['identifier'])) {
        $params['identifier'] = (string)$options['identifier'];
    }
    if (isset($options['answerbacks'])) {
        $params['answerbacks'] = $options['answerbacks'];
    }
    if (isset($options['reportback_url'])) {
        $params['reportback_url'] = (string)$options['reportback_url'];
    }

    // result フィールド: transaction_id, authentic_number, created_at, expires_at, customer_numbers
    return ostiaries_request('NewTransaction', $params, $api_key, $access_key);
}

/**
 * GetTransaction — トランザクション情報取得
 *
 * @param  string $api_key         x-api-key
 * @param  string $access_key      署名用アクセスキー
 * @param  string $service_id      サービス ID
 * @param  string $transaction_id  トランザクション ID
 * @return array  レスポンス
 */
function ostiaries_get_transaction($api_key, $access_key, $service_id, $transaction_id)
{
    return ostiaries_request('GetTransaction', [
        'service_id'     => $service_id,
        'transaction_id' => $transaction_id,
    ], $api_key, $access_key);
}

/**
 * GetTransactionStatus — トランザクション状態取得（軽量）
 *
 * @param  string $api_key         x-api-key
 * @param  string $access_key      署名用アクセスキー
 * @param  string $service_id      サービス ID
 * @param  string $transaction_id  トランザクション ID
 * @return array  レスポンス
 */
function ostiaries_get_transaction_status($api_key, $access_key, $service_id, $transaction_id)
{
    return ostiaries_request('GetTransactionStatus', [
        'service_id'     => $service_id,
        'transaction_id' => $transaction_id,
    ], $api_key, $access_key);
}

/**
 * StartAuthentication — 着信認証開始
 *
 * @param  string $api_key         x-api-key
 * @param  string $access_key      署名用アクセスキー
 * @param  string $service_id      サービス ID
 * @param  string $transaction_id  トランザクション ID
 * @return array  レスポンス
 */
function ostiaries_start_authentication($api_key, $access_key, $service_id, $transaction_id)
{
    return ostiaries_request('StartAuthentication', [
        'service_id'     => $service_id,
        'transaction_id' => $transaction_id,
    ], $api_key, $access_key);
}

/**
 * CancelAuthentication — 着信認証キャンセル
 *
 * @param  string $api_key         x-api-key
 * @param  string $access_key      署名用アクセスキー
 * @param  string $service_id      サービス ID
 * @param  string $transaction_id  トランザクション ID
 * @return array  レスポンス
 */
function ostiaries_cancel_authentication($api_key, $access_key, $service_id, $transaction_id)
{
    return ostiaries_request('CancelAuthentication', [
        'service_id'     => $service_id,
        'transaction_id' => $transaction_id,
    ], $api_key, $access_key);
}

// ============================================================
// レスポンス解析ユーティリティ
// ============================================================

/** レスポンスが成功かどうか */
function ostiaries_is_success($response)
{
    return isset($response['response']) && $response['response'] === 'success';
}

/** result オブジェクトを返す（成功時のみ有効） */
function ostiaries_get_result($response)
{
    return $response['result'] ?? null;
}

/** エラーコードを返す（失敗時） */
function ostiaries_get_error_code($response)
{
    return $response['error']['code'] ?? null;
}

/** エラーメッセージを返す（失敗時） */
function ostiaries_get_error_message($response)
{
    return $response['error']['message'] ?? '';
}

// ============================================================
// レポートバック受信・署名検証（CP側が実装するスクリプト用）
// ============================================================

/**
 * レポートバックを受信し、署名検証後にパラメータを返す
 *
 * @return array|false 検証済みパラメータ配列、失敗時は false
 */
function ostiaries_receive_reportback()
{
    // X-Ostiaries-Signature ヘッダ取得
    $jws = _ostiaries_get_signature_header();
    if (empty($jws)) {
        error_log('[ostiaries] X-Ostiaries-Signature ヘッダが見つかりません');
        return false;
    }

    // リクエストボディ取得（生データ）
    $raw_body = file_get_contents('php://input');

    // 署名検証
    if (!ostiaries_verify_reportback_signature($jws, $raw_body)) {
        error_log('[ostiaries] レポートバック署名検証失敗');
        return false;
    }

    // Content-Type に応じてパラメータをパース
    $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

    if (strpos($content_type, 'application/json') !== false) {
        $params = json_decode($raw_body, true) ?: [];
    } elseif (strpos($content_type, 'application/xml') !== false
           || strpos($content_type, 'text/xml') !== false) {
        $xml    = simplexml_load_string($raw_body);
        $params = $xml ? json_decode(json_encode($xml), true) : [];
    } else {
        // デフォルト: x-www-form-urlencoded
        parse_str($raw_body, $params);
    }

    return $params;
}

/**
 * レポートバック署名を検証する（ES512 + ダイジェスト比較）
 *
 * @param  string $jws      X-Ostiaries-Signature ヘッダの値
 * @param  string $raw_body リクエストボディ（生）
 * @return bool   検証結果
 */
function ostiaries_verify_reportback_signature($jws, $raw_body)
{
    // JWS を 3 パートに分割
    $parts = explode('.', $jws);
    if (count($parts) !== 3) {
        return false;
    }

    // ヘッダのデコード
    $header = json_decode(_ostiaries_base64url_decode($parts[0]), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'ES512') {
        return false;
    }

    // kid から公開鍵を取得
    $kid = $header['kid'] ?? '';
    if (empty($kid)) {
        return false;
    }

    $pubkey_pem = ostiaries_get_public_key($kid);
    if (empty($pubkey_pem)) {
        error_log("[ostiaries] 公開鍵の取得に失敗しました (kid={$kid})");
        return false;
    }

    // ペイロードのダイジェストを取得
    $payload        = json_decode(_ostiaries_base64url_decode($parts[1]), true);
    $payload_digest = $payload['digest'] ?? '';

    // 受信ボディのダイジェストを計算して比較
    $body_digest = _ostiaries_base64url_encode(hash('sha512', $raw_body, true));
    if (!hash_equals($body_digest, $payload_digest)) {
        return false;
    }

    // ES512 署名検証（openssl）
    $signing_input = $parts[0] . '.' . $parts[1];
    $sig_raw       = _ostiaries_base64url_decode($parts[2]);
    $sig_der       = _ostiaries_ecdsa_raw_to_der($sig_raw);
    if ($sig_der === false) {
        return false;
    }

    $pub = openssl_pkey_get_public($pubkey_pem);
    if ($pub === false) {
        return false;
    }

    return openssl_verify($signing_input, $sig_der, $pub, OPENSSL_ALGO_SHA512) === 1;
}

/**
 * GetPublicKey API で公開鍵 PEM を取得する
 *
 * @param  string $kid 公開鍵 ID
 * @return string|false PEM 文字列
 */
function ostiaries_get_public_key($kid)
{
    $url     = OSTIARIES_API_BASE . 'GetPublicKey/' . rawurlencode($kid);
    $context = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $result = @file_get_contents($url, false, $context);
    return ($result !== false && !empty($result)) ? $result : false;
}

/**
 * X-Ostiaries-Signature ヘッダを取得する
 */
function _ostiaries_get_signature_header()
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $val) {
            if (strtolower($key) === 'x-ostiaries-signature') {
                return trim($val);
            }
        }
    }
    // Apache 以外の環境（Nginx 等）
    return isset($_SERVER['HTTP_X_OSTIARIES_SIGNATURE'])
        ? trim($_SERVER['HTTP_X_OSTIARIES_SIGNATURE'])
        : '';
}

/**
 * ECDSA 署名の Raw (r||s) 形式を DER 形式に変換する
 * ES512 では r, s はそれぞれ 66 バイト
 *
 * @param  string      $raw r||s の連結バイト列
 * @return string|false DER 形式署名
 */
function _ostiaries_ecdsa_raw_to_der($raw)
{
    $len = strlen($raw);
    if ($len % 2 !== 0) {
        return false;
    }

    $half = intdiv($len, 2);
    $r    = ltrim(substr($raw, 0, $half), "\x00") ?: "\x00";
    $s    = ltrim(substr($raw, $half),    "\x00") ?: "\x00";

    // 最上位ビットが 1 の場合は 0x00 を先頭に付加（負数誤認防止）
    if (ord($r[0]) & 0x80) { $r = "\x00" . $r; }
    if (ord($s[0]) & 0x80) { $s = "\x00" . $s; }

    // DER INTEGER エンコード
    $r_der = "\x02" . chr(strlen($r)) . $r;
    $s_der = "\x02" . chr(strlen($s)) . $s;
    $seq   = $r_der . $s_der;

    return "\x30" . chr(strlen($seq)) . $seq;
}

// ============================================================
// JSON レスポンスヘルパー（コントローラ共通）
// ============================================================

function _json_ok(array $data)
{
    echo json_encode(['result' => 'ok', 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function _json_error($message, $code = 500)
{
    http_response_code($code);
    echo json_encode(['result' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function _json_api_error(array $response)
{
    $code = ostiaries_get_error_code($response);
    $msg  = ostiaries_get_error_message($response);
    _json_error("[{$code}] {$msg}", 502);
}
