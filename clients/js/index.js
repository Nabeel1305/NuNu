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

export class Client {
  /** @param {{apiKey: string, baseUrl: string, fetch?: typeof fetch}} options */
  constructor({ apiKey, baseUrl, fetch: fetchImpl = globalThis.fetch }) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.fetch = fetchImpl;
  }

  upsertSubscriber(reference, phone = null) {
    return this.#request('PUT', `/subscribers/${encodeURIComponent(reference)}`, { phone });
  }

  upsertMerchant(reference, name, accountReference) {
    return this.#request('PUT', `/merchants/${encodeURIComponent(reference)}`, { name, account_reference: accountReference });
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
