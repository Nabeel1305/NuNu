import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { Client, ApiError, verifyWebhook } from './index.js';

const reply = (status, body) => async (url, init) => {
  reply.last = { url, init };
  return { ok: status < 400, status, text: async () => JSON.stringify(body) };
};

const sign = (secret, t, body) => `t=${t},v1=${createHmac('sha256', secret).update(`${t}.${body}`).digest('hex')}`;

test('issueCode sends the key, body and an idempotency key', async () => {
  const client = new Client({ apiKey: 'opk_test', baseUrl: 'https://api.test/api/v1/', fetch: reply(201, { code: '123456789012' }) });
  const result = await client.issueCode({ amount_minor: 100 }, 'fixed');

  assert.equal(result.code, '123456789012');
  assert.equal(reply.last.url, 'https://api.test/api/v1/codes');
  assert.equal(reply.last.init.headers.Authorization, 'Bearer opk_test');
  assert.equal(reply.last.init.headers['Idempotency-Key'], 'fixed');
  assert.equal(reply.last.init.body, '{"amount_minor":100}');
});

test('each call without a key gets a fresh idempotency key', async () => {
  const client = new Client({ apiKey: 'k', baseUrl: 'https://x', fetch: reply(201, {}) });
  await client.issueCode({});
  const first = reply.last.init.headers['Idempotency-Key'];
  await client.issueCode({});
  assert.notEqual(first, reply.last.init.headers['Idempotency-Key']);
});

test('an error response throws ApiError with the code', async () => {
  const client = new Client({ apiKey: 'k', baseUrl: 'https://x', fetch: reply(422, { error: { code: 'settlement_rejected', message: 'No funds' } }) });
  await assert.rejects(client.issueCode({}), (e) => e instanceof ApiError && e.status === 422 && e.code === 'settlement_rejected' && e.message === 'No funds');
});

test('path values are encoded', async () => {
  const client = new Client({ apiKey: 'k', baseUrl: 'https://api.test', fetch: reply(200, {}) });
  await client.upsertSubscriber('a b/c', { accountNumber: '2000000001', bankCode: '058', phone: '+234' });
  assert.equal(reply.last.url, 'https://api.test/subscribers/a%20b%2Fc');
});

test('subscribers and merchants are sent with their bank account', async () => {
  const client = new Client({ apiKey: 'k', baseUrl: 'https://api.test', fetch: reply(200, {}) });

  await client.upsertSubscriber('cust', { accountNumber: '2000000001', bankCode: '058' });
  assert.deepEqual(JSON.parse(reply.last.init.body), { account_number: '2000000001', bank_code: '058', phone: null });

  await client.upsertMerchant('shop', { name: 'Shop', accountNumber: '3000000001', bankCode: '011' });
  assert.deepEqual(JSON.parse(reply.last.init.body), { name: 'Shop', account_number: '3000000001', bank_code: '011', account_reference: null });
});

test('webhook verification accepts a good signature and rejects bad, stale or malformed ones', () => {
  const body = '{"a":1}';
  const header = sign('whsec_x', 1000, body);

  assert.equal(verifyWebhook('whsec_x', header, body, { now: 1050 }), true);
  assert.equal(verifyWebhook('whsec_x', header, '{"a":2}', { now: 1050 }), false);
  assert.equal(verifyWebhook('whsec_other', header, body, { now: 1050 }), false);
  assert.equal(verifyWebhook('whsec_x', header, body, { now: 2000 }), false);
  assert.equal(verifyWebhook('whsec_x', 'garbage', body, { now: 1050 }), false);
});

test('verifies a signature produced by the platform (shared fixed vector)', () => {
  // Same vector asserted in tests/Unit/PhpClientTest.php.
  const header = 't=1000,v1=8e34e23174d8364a4b53cfafdf794866781da3c798cb1437af1ff66b34777988';
  assert.equal(verifyWebhook('whsec_x', header, '{"a":1}', { now: 1000 }), true);
});
