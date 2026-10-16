import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto';

export class ApiError extends Error {
  constructor(status, code, message, body) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

/** Check a delivery's Offline-Signature header against the raw body (a string or Buffer, exactly as received). */
export function verifyWebhook(secret, header, rawBody, { toleranceSeconds = 300, now = Math.floor(Date.now() / 1000) } = {}) {
  const match = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(header ?? '');
  if (!match) return false;
  if (Math.abs(now - Number(match[1])) > toleranceSeconds) return false;

  const expected = createHmac('sha256', secret).update(`${match[1]}.`).update(rawBody).digest();
  return timingSafeEqual(expected, Buffer.from(match[2], 'hex'));
}

const WINDOW_SECONDS = 300;

/**
 * Build the 40-digit code a phone dials with no connection. Reference implementation of the format;
 * the server accepts the previous, current and next 5-minute window, so the phone's clock must be
 * within about 5 minutes of the real time.
 *
 * @param {object} p
 * @param {string|Buffer} p.deviceKey   key from enrolDevice(): base64 string or the 32 raw bytes
 * @param {string} p.deviceShortId      8 digits, from enrolDevice()
 * @param {number} p.counter            strictly greater than the last one used (start at last_counter + 1), max 99999
 * @param {number} p.amountMinor        whole number of kobo, 1 to 99,999,999
 * @param {string} p.merchantShortId    8 digits, from the merchant's QR / getMerchant()
 * @param {{accountNumber: string, bankCode: string}} p.source       the subscriber's own account
 * @param {{accountNumber: string, bankCode: string}} p.destination  the merchant's account, as shown on its QR
 * @param {string} [p.currency='NGN']
 * @param {number} [p.now]              unix seconds
 */
export function buildDeviceCode({ deviceKey, deviceShortId, counter, amountMinor, merchantShortId, source, destination, currency = 'NGN', now = Math.floor(Date.now() / 1000) }) {
  if (!/^\d{8}$/.test(deviceShortId) || !/^\d{8}$/.test(merchantShortId)) throw new RangeError('short ids must be 8 digits');
  if (!Number.isInteger(counter) || counter < 1 || counter > 99999) throw new RangeError('counter must be 1..99999');
  if (!Number.isInteger(amountMinor) || amountMinor < 1 || amountMinor > 99999999) throw new RangeError('amountMinor must be 1..99999999');

  const key = Buffer.isBuffer(deviceKey) ? deviceKey : Buffer.from(deviceKey, 'base64');
  const window = Math.floor(now / WINDOW_SECONDS);
  const canonical = ['nunu-d1', deviceShortId, counter, amountMinor, merchantShortId, window, currency.toUpperCase(),
    source.accountNumber, source.bankCode, destination.accountNumber, destination.bankCode].join('|');

  // first 5 bytes of HMAC-SHA256, big-endian, mod 10^10, zero-padded to 10 digits
  const mac = createHmac('sha256', key).update(canonical).digest();
  const value = mac.readUIntBE(0, 5) % 10_000_000_000;

  return '1' + deviceShortId + String(counter).padStart(5, '0') + String(amountMinor).padStart(8, '0') + merchantShortId + String(value).padStart(10, '0');
}

/** Number, pauses, code and "#": what a dialler needs. On a shared voice number pass the tenant's 4-digit short code as `prefix`. */
export function dialString(voiceNumber, code, prefix = '') {
  return `${voiceNumber},,,${prefix}${code}#`;
}

export class Client {
  /** @param {{apiKey: string, baseUrl: string, fetch?: typeof fetch}} options */
  constructor({ apiKey, baseUrl, fetch: fetchImpl = globalThis.fetch }) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.fetch = fetchImpl;
  }

  /** The account number and bank code are where the payer's funds are held and debited from. */
  upsertSubscriber(reference, { accountNumber, bankCode, phone = null }) {
    return this.#request('PUT', `/subscribers/${encodeURIComponent(reference)}`, { account_number: accountNumber, bank_code: bankCode, phone });
  }

  /** The account number and bank code are where captured funds are credited. accountReference is your own optional label. */
  upsertMerchant(reference, { name, accountNumber, bankCode, accountReference = null }) {
    return this.#request('PUT', `/merchants/${encodeURIComponent(reference)}`, { name, account_number: accountNumber, bank_code: bankCode, account_reference: accountReference });
  }

  /** The returned `secret` is shown once; store it. */
  createWebhookEndpoint(url, events) {
    return this.#request('POST', '/webhook-endpoints', events ? { url, events } : { url });
  }

  /**
   * Issue a code. Pass the same idempotencyKey when retrying the same payment,
   * so a timeout can never produce a second code. The returned `code` is shown once.
   */
  issueCode(params, idempotencyKey = randomUUID()) {
    return this.#request('POST', '/codes', params, idempotencyKey);
  }

  getCode(id) {
    return this.#request('GET', `/codes/${encodeURIComponent(id)}`);
  }

  cancelCode(id, idempotencyKey = randomUUID()) {
    return this.#request('POST', `/codes/${encodeURIComponent(id)}/cancel`, undefined, idempotencyKey);
  }

  /** Enrol a phone for a subscriber. The returned device_key is the phone's secret: keep it in secure storage. Safe to repeat. */
  enrolDevice(subscriberReference, deviceId) {
    return this.#request('POST', `/subscribers/${encodeURIComponent(subscriberReference)}/devices`, { device_id: deviceId });
  }

  listDevices(subscriberReference) {
    return this.#request('GET', `/subscribers/${encodeURIComponent(subscriberReference)}/devices`);
  }

  revokeDevice(subscriberReference, deviceId) {
    return this.#request('DELETE', `/subscribers/${encodeURIComponent(subscriberReference)}/devices/${encodeURIComponent(deviceId)}`);
  }

  /** Includes the short_id a phone puts in an offline code. */
  getMerchant(reference) {
    return this.#request('GET', `/merchants/${encodeURIComponent(reference)}`);
  }

  getTransaction(id) {
    return this.#request('GET', `/transactions/${encodeURIComponent(id)}`);
  }

  async #request(method, path, body, idempotencyKey) {
    const headers = { Authorization: `Bearer ${this.apiKey}`, Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;

    const response = await this.fetch(this.baseUrl + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await response.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch { /* leave empty */ }

    if (!response.ok) {
      throw new ApiError(response.status, data.error?.code ?? `http_${response.status}`, data.error?.message ?? data.message ?? 'Request failed.', data);
    }
    return data;
  }
}
