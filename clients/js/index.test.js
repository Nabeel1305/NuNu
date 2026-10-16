import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { Client, ApiError, verifyWebhook, buildDeviceCode, dialString } from './index.js';

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

// Shared with tests/Unit/DeviceCodeFormatTest.php: the same inputs must give the same digits in every implementation.
const VECTOR = {
  deviceKey: 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
  source: { accountNumber: '2000000001', bankCode: '058' },
  destination: { accountNumber: '3000000001', bankCode: '011' },
  now: 1791600000,
};

test('device codes match the server test vectors byte for byte', () => {
  assert.equal(
    buildDeviceCode({ ...VECTOR, deviceShortId: '48201937', counter: 42, amountMinor: 250000, merchantShortId: '70011234' }),
    '1482019370004200250000700112344868933947',
  );
  assert.equal(
    buildDeviceCode({ ...VECTOR, deviceShortId: '00000007', counter: 1, amountMinor: 100, merchantShortId: '00000009' }),
    '1000000070000100000100000000090281068932',
  );
});

test('device codes are 40 digits and reject out-of-range input', () => {
  const ok = { ...VECTOR, deviceShortId: '48201937', counter: 1, amountMinor: 1, merchantShortId: '70011234' };
  assert.match(buildDeviceCode(ok), /^\d{40}$/);
  assert.throws(() => buildDeviceCode({ ...ok, counter: 0 }), RangeError);
  assert.throws(() => buildDeviceCode({ ...ok, counter: 100000 }), RangeError);
  assert.throws(() => buildDeviceCode({ ...ok, amountMinor: 0 }), RangeError);
  assert.throws(() => buildDeviceCode({ ...ok, deviceShortId: '123' }), RangeError);
});

test('a code from another window or with a different amount has a different MAC', () => {
  const base = { ...VECTOR, deviceShortId: '48201937', counter: 42, amountMinor: 250000, merchantShortId: '70011234' };
  const code = buildDeviceCode(base);
  assert.notEqual(buildDeviceCode({ ...base, now: base.now + 300 }).slice(30), code.slice(30));
  assert.notEqual(buildDeviceCode({ ...base, amountMinor: 250001 }).slice(30), code.slice(30));
  assert.equal(buildDeviceCode({ ...base, now: base.now + 299 }), buildDeviceCode({ ...base, now: base.now + 1 }));
});

test('dialString joins number, pauses, optional prefix, code and #', () => {
  assert.equal(dialString('+2347000000001', '123'), '+2347000000001,,,123#');
  assert.equal(dialString('+2347000000099', '123', '4821'), '+2347000000099,,,4821123#');
});

test('device endpoints use the right paths', async () => {
  const client = new Client({ apiKey: 'k', baseUrl: 'https://api.test', fetch: reply(200, {}) });
  await client.enrolDevice('cust 1', 'install-0001');
  assert.equal(reply.last.url, 'https://api.test/subscribers/cust%201/devices');
  assert.deepEqual(JSON.parse(reply.last.init.body), { device_id: 'install-0001' });
  await client.revokeDevice('cust-1', 'a/b');
  assert.equal(reply.last.url, 'https://api.test/subscribers/cust-1/devices/a%2Fb');
  assert.equal(reply.last.init.method, 'DELETE');
  await client.getMerchant('shop-1');
  assert.equal(reply.last.url, 'https://api.test/merchants/shop-1');
});
