/**
 * Ostiaries 着信認証 View
 *
 * 使い方:
 *   const auth = new OstiariesAuth({
 *     endpoints: {
 *       start:  '/accounts/ostiaries_start',
 *       status: '/accounts/ostiaries_status',
 *       result: '/accounts/ostiaries_result',
 *       cancel: '/accounts/ostiaries_cancel',
 *     },
 *     customerNumbers:  ['09012345678'],
 *     identifier:       'order_001',         // 任意
 *     reportbackUrl:    'https://example.com/ostiaries_reportback.php', // 任意
 *     waitTime:         120,                 // 任意
 *     pollInterval:     3000,                // ポーリング間隔 ms (デフォルト 3000)
 *     onDialNumber:     (dialNumber) => { ... },          // 発信先番号が確定したとき
 *     onStatusChange:   (status) => { ... },              // ステータスが変化するたびに
 *     onComplete:       (result) => { ... },              // 認証完了（auth_result: ok/ng）
 *     onFailed:         (status) => { ... },              // failed / cancelled / expired
 *     onError:          (message) => { ... },             // 通信エラー等
 *   });
 *
 *   auth.start();    // 認証フロー開始
 *   auth.cancel();   // 途中でキャンセル
 */
class OstiariesAuth {
  #endpoints;
  #customerNumbers;
  #identifier;
  #reportbackUrl;
  #waitTime;
  #pollInterval;
  #callbacks;

  #transactionId = null;
  #timerId       = null;
  #stopped       = false;

  static #TERMINAL_STATUSES = new Set(['completed', 'failed', 'cancelled', 'expired']);

  constructor(options = {}) {
    const ep = options.endpoints ?? {};
    this.#endpoints = {
      start:  ep.start  ?? '/accounts/ostiaries_start',
      status: ep.status ?? '/accounts/ostiaries_status',
      result: ep.result ?? '/accounts/ostiaries_result',
      cancel: ep.cancel ?? '/accounts/ostiaries_cancel',
    };

    this.#customerNumbers = options.customerNumbers ?? [];
    this.#identifier      = options.identifier      ?? null;
    this.#reportbackUrl   = options.reportbackUrl   ?? null;
    this.#waitTime        = options.waitTime        ?? null;
    this.#pollInterval    = options.pollInterval    ?? 3000;

    this.#callbacks = {
      onDialNumber:   options.onDialNumber   ?? (() => {}),
      onStatusChange: options.onStatusChange ?? (() => {}),
      onComplete:     options.onComplete     ?? (() => {}),
      onFailed:       options.onFailed       ?? (() => {}),
      onError:        options.onError        ?? ((msg) => console.error('[OstiariesAuth]', msg)),
    };
  }

  // ============================================================
  // 公開メソッド
  // ============================================================

  /** 認証フローを開始する */
  async start() {
    this.#stopped = false;

    try {
      const body = { customer_numbers: this.#customerNumbers };
      if (this.#identifier)    body.identifier     = this.#identifier;
      if (this.#reportbackUrl) body.reportback_url = this.#reportbackUrl;
      if (this.#waitTime)      body.wait_time      = this.#waitTime;

      const res = await this.#post(this.#endpoints.start, body);
      if (!res) return;

      this.#transactionId = res.data.transaction_id;
      this.#callbacks.onDialNumber(res.data.dial_number);

      this.#schedulePoll();

    } catch (err) {
      this.#callbacks.onError(String(err));
    }
  }

  /** 認証をキャンセルする */
  async cancel() {
    this.#stopped = true;
    this.#clearTimer();

    if (!this.#transactionId) return;

    try {
      await this.#post(this.#endpoints.cancel, { transaction_id: this.#transactionId });
    } catch (err) {
      this.#callbacks.onError(String(err));
    }
  }

  // ============================================================
  // 内部処理
  // ============================================================

  #schedulePoll() {
    if (this.#stopped) return;
    this.#timerId = setTimeout(() => this.#poll(), this.#pollInterval);
  }

  async #poll() {
    if (this.#stopped) return;

    try {
      const res = await this.#post(this.#endpoints.status, {
        transaction_id: this.#transactionId,
      });

      // 429 リトライ
      if (!res) {
        this.#schedulePoll();
        return;
      }

      const status = res.data.status;
      this.#callbacks.onStatusChange(status);

      if (OstiariesAuth.#TERMINAL_STATUSES.has(status)) {
        this.#stopped = true;

        if (status === 'completed') {
          const result = await this.#post(this.#endpoints.result, {
            transaction_id: this.#transactionId,
          });
          if (result) {
            this.#callbacks.onComplete(result.data);
          }
        } else {
          this.#callbacks.onFailed(status);
        }
      } else {
        this.#schedulePoll();
      }

    } catch (err) {
      this.#callbacks.onError(String(err));
    }
  }

  /** 指定 URL へ POST してレスポンスを返す。エラー時は null を返す */
  async #post(url, body) {
    const res = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
    });

    if (res.status === 429) return null; // リトライ可

    const json = await res.json();

    if (json.result !== 'ok') {
      this.#callbacks.onError(json.message ?? 'Unknown error');
      return null;
    }

    return json;
  }

  #clearTimer() {
    if (this.#timerId !== null) {
      clearTimeout(this.#timerId);
      this.#timerId = null;
    }
  }
}
