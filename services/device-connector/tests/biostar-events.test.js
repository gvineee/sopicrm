import assert from 'node:assert/strict';
import test from 'node:test';

import { biostarEventCode, classifyBiostarEvent } from '../src/adapters/biostar-event-taxonomy.js';
import { SupremaDeviceGatewayAdapter } from '../src/adapters/suprema-device-gateway.js';

/**
 * These mirror tests/Feature/Devices/BiostarEventImportTest.php. The taxonomy
 * exists on both sides of the wire and the two copies have to agree, so both
 * are pinned against the same real codes a live server reported.
 */

test('granted and refused reads are told apart', () => {
    assert.equal(classifyBiostarEvent(4102), 'access_granted'); // VERIFY_SUCCESS_CARD
    assert.equal(classifyBiostarEvent(4867), 'access_granted'); // IDENTIFY_SUCCESS_FACE
    assert.equal(classifyBiostarEvent(4354), 'access_denied'); // VERIFY_FAIL_CARD
    assert.equal(classifyBiostarEvent(6401), 'access_denied'); // ACCESS_DENIED_ACCESS_GROUP
    assert.equal(classifyBiostarEvent(6403), 'access_denied'); // ACCESS_DENIED_EXPIRED
});

test('a refused badge maps to the exact code the attendance rebuild excludes', () => {
    // Laravel's ReconstructAttendanceSessionsAction excludes `access_denied`
    // and nothing else, so this has to be that literal string.
    assert.equal(biostarEventCode(6401), 'access_denied');
    assert.equal(biostarEventCode(4102), 'access_granted');
});

test('an event that says nothing about a person keeps its BioStar number', () => {
    for (const code of [20480, 20736, 12544, 8192, 4095]) {
        assert.equal(classifyBiostarEvent(code), 'other');
        assert.equal(biostarEventCode(code), `biostar:${code}`);
    }
});

test('an unknown code is never mistaken for a granted entry', () => {
    for (const code of [null, undefined, '', 'nonsense', 0, 999999]) {
        assert.notEqual(classifyBiostarEvent(code), 'access_granted');
    }
});

/**
 * The timestamp defect: BioStar reports both `datetime` (what the reader
 * believed) and `server_datetime` (when the server recorded it). On the live
 * install the reader runs three hours behind real UTC while the server matches
 * it, so forwarding `datetime` as the event time made every worked hour wrong.
 */
test('pullEvents forwards the server time and keeps the device claim beside it', async () => {
    const rows = [
        {
            id: '101',
            datetime: '2026-09-25T07:39:38.00Z',
            server_datetime: '2026-09-25T10:39:43.00Z',
            device_id: { id: '544452272' },
            event_type_id: { code: '4102' },
        },
    ];

    const adapter = makeAdapter(rows);
    const [event] = await adapter.pullEvents('544452272', { lastNativeEventId: 0 }, 10);

    assert.equal(event.server_time, '2026-09-25T10:39:43.00Z');
    assert.equal(event.raw_device_time, '2026-09-25T07:39:38.00Z');
    assert.equal(event.event_code, 'access_granted');
    assert.equal(event.ingestion_source, 'biostar-import');
    // Reported as a fact so a wrong clock is visible rather than inferred
    // from strange attendance later.
    assert.equal(event.clock_offset_seconds, -10805);
});

test('a row for another device is never attributed to this one', async () => {
    const rows = [
        { id: '1', datetime: '2026-09-25T07:00:00.00Z', server_datetime: '2026-09-25T10:00:00.00Z', device_id: { id: '999' }, event_type_id: { code: '4102' } },
        { id: '2', datetime: '2026-09-25T07:01:00.00Z', server_datetime: '2026-09-25T10:01:00.00Z', device_id: { id: '544452272' }, event_type_id: { code: '4102' } },
    ];

    const adapter = makeAdapter(rows);
    const events = await adapter.pullEvents('544452272', { lastNativeEventId: 0 }, 10);

    assert.equal(events.length, 1);
    assert.equal(events[0].native_event_id, 2);
});

test('events already imported are not sent again', async () => {
    const rows = [1, 2, 3].map((n) => ({
        id: String(n),
        datetime: '2026-09-25T07:00:00.00Z',
        server_datetime: '2026-09-25T10:00:00.00Z',
        device_id: { id: '544452272' },
        event_type_id: { code: '4102' },
    }));

    const adapter = makeAdapter(rows);
    const events = await adapter.pullEvents('544452272', { lastNativeEventId: 2 }, 10);

    assert.deepEqual(events.map((e) => e.native_event_id), [3]);
});

test('the search really asks the server to narrow by device', async () => {
    let sentBody = null;
    const adapter = makeAdapter([], (body) => { sentBody = body; });

    await adapter.pullEvents('544452273', { lastNativeEventId: 0 }, 10);

    // Verified working against a live server. Filtering client-side instead
    // let one chatty door's lock/unlock traffic fill the window before a quiet
    // reader's badge read was ever reached.
    const condition = sentBody?.Query?.conditions?.find((c) => c.column === 'device_id');
    assert.ok(condition, 'expected a server-side device_id condition');
    assert.deepEqual(condition.values, ['544452273']);
});

/** An adapter whose HTTP layer is replaced by a canned event-search response. */
function makeAdapter(rows, onBody = () => {}) {
    const env = {
        DEVICE_CONNECTOR_MODE: 'suprema',
        BIOSTAR_BASE_URL: 'https://biostar.invalid',
        BIOSTAR_USERNAME: 'probe',
        BIOSTAR_PASSWORD: 'probe',
        BIOSTAR_VERIFY_TLS: 'false',
    };

    const fetchImpl = async (url, options = {}) => {
        const body = options.body ? JSON.parse(options.body) : null;

        if (String(url).endsWith('/api/login')) {
            return {
                ok: true,
                status: 200,
                headers: { get: (h) => (h.toLowerCase() === 'bs-session-id' ? 'test-session' : null) },
                json: async () => ({}),
                text: async () => '{}',
            };
        }

        onBody(body);

        return {
            ok: true,
            status: 200,
            headers: { get: () => null },
            json: async () => ({ EventCollection: { rows } }),
            text: async () => JSON.stringify({ EventCollection: { rows } }),
        };
    };

    return new SupremaDeviceGatewayAdapter(env, fetchImpl);
}

/**
 * BioStar reports a card id in DECIMAL; the CRM's ingest endpoint expects hex
 * and converts it back to decimal for `canonical_identifier`. Getting this
 * backwards does not fail loudly — it matches no credential and files a
 * triage row for a card nobody owns.
 */
test('a decimal card id is converted so the CRM decodes the same number back', () => {
    // The real card on the live server.
    assert.equal(SupremaDeviceGatewayAdapter.decimalCardIdToHex('69410222'), '4231DAE');
    assert.equal(BigInt('0x4231DAE').toString(), '69410222');

    // Sending the decimal through unchanged would have arrived as this — a
    // different card entirely, and silently so.
    assert.notEqual(BigInt('0x69410222').toString(), '69410222');
});

test('a card id that is not a plain decimal is refused rather than guessed', () => {
    for (const value of [null, undefined, '', 'ABC', '12AB', '  ']) {
        assert.equal(SupremaDeviceGatewayAdapter.decimalCardIdToHex(value), null);
    }
});

test('an event carries the card of the person who swiped, so it can reach an employee', async () => {
    const rows = [{
        id: '500',
        datetime: '2026-09-25T07:00:00.00Z',
        server_datetime: '2026-09-25T10:00:00.00Z',
        device_id: { id: '544452272' },
        event_type_id: { code: '4102' },
        user_id: { user_id: '2', name: 'irakli gvineria' },
    }];

    const adapter = makeAdapterWithUsers(rows, {
        users: [{ user_id: '2', name: 'irakli gvineria', card_count: '1' }],
        detail: { 2: { cards: [{ card_id: '69410222', is_assigned: 'true', is_blocked: 'false', card_type: { name: 'CSN' } }] } },
    });

    const [event] = await adapter.pullEvents('544452272', { lastNativeEventId: 0 }, 10);

    // BioStar events name the PERSON and never the card, while the CRM matches
    // a swipe to an employee BY card. Without this the import would produce no
    // attendance at all.
    assert.equal(event.card_type, 'CSN');
    assert.equal(event.card_hex, '4231DAE');
});

test('a person with no card on file still imports, unmatched rather than guessed', async () => {
    const rows = [{
        id: '501',
        datetime: '2026-09-25T07:00:00.00Z',
        server_datetime: '2026-09-25T10:00:00.00Z',
        device_id: { id: '544452272' },
        event_type_id: { code: '4102' },
        user_id: { user_id: '9', name: 'no card' },
    }];

    const adapter = makeAdapterWithUsers(rows, { users: [{ user_id: '9', card_count: '0' }], detail: {} });
    const [event] = await adapter.pullEvents('544452272', { lastNativeEventId: 0 }, 10);

    assert.equal(event.card_hex, undefined);
    // The event is still imported — the raw log stays complete and the swipe
    // shows up for triage instead of being attributed to a guess.
    assert.equal(event.event_code, 'access_granted');
});

/** An adapter whose users/detail/event endpoints are all canned. */
function makeAdapterWithUsers(eventRows, { users, detail }) {
    const env = {
        DEVICE_CONNECTOR_MODE: 'suprema',
        BIOSTAR_BASE_URL: 'https://biostar.invalid',
        BIOSTAR_USERNAME: 'probe',
        BIOSTAR_PASSWORD: 'probe',
        BIOSTAR_VERIFY_TLS: 'false',
    };

    const respond = (payload) => ({
        ok: true,
        status: 200,
        headers: { get: (h) => (h?.toLowerCase() === 'bs-session-id' ? 'test-session' : null) },
        json: async () => payload,
        text: async () => JSON.stringify(payload),
    });

    const fetchImpl = async (url) => {
        const path = String(url);

        if (path.endsWith('/api/login')) return respond({});
        if (path.includes('/api/users?')) return respond({ UserCollection: { rows: users } });

        const match = path.match(/\/api\/users\/(\d+)$/);
        if (match) return respond({ User: detail[match[1]] ?? {} });

        return respond({ EventCollection: { rows: eventRows } });
    };

    return new SupremaDeviceGatewayAdapter(env, fetchImpl);
}
