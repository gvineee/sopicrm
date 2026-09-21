import { randomUUID } from 'node:crypto';

export class LaravelConnectorClient {
    constructor({ baseUrl, token, connectorVersion, fetchImpl = globalThis.fetch }) {
        this.baseUrl = baseUrl.replace(/\/$/, ''); this.token = token; this.connectorVersion = connectorVersion; this.fetch = fetchImpl;
    }

    async commands(deviceId, limit = 100) { return this.request(`/devices/${encodeURIComponent(deviceId)}/commands?limit=${limit}`); }
    async acknowledge(deviceId, commandId, result) {
        return this.request(`/devices/${encodeURIComponent(deviceId)}/commands/${encodeURIComponent(commandId)}/acknowledge`, { method: 'POST', body: result, idempotencyKey: `command-ack:${commandId}:${result.result}` });
    }
    async heartbeat(deviceId, heartbeat) {
        return this.request(`/devices/${encodeURIComponent(deviceId)}/heartbeat`, { method: 'POST', body: { connector_version: this.connectorVersion, ...heartbeat }, idempotencyKey: `heartbeat:${deviceId}:${Math.floor(Date.now() / 10_000)}` });
    }
    async events(deviceId, events) {
        const first = events[0]; const last = events.at(-1);
        const batchKey = `${first.stream_epoch}:${first.native_event_id}-${last.stream_epoch}:${last.native_event_id}`;
        return this.request(`/devices/${encodeURIComponent(deviceId)}/events`, { method: 'POST', body: { events }, idempotencyKey: `events:${deviceId}:${batchKey}` });
    }

    async request(path, { method = 'GET', body, idempotencyKey } = {}) {
        if (!this.token) throw new Error('DEVICE_CONNECTOR_TOKEN is required. Issue an organization-bound Sanctum machine token.');
        const headers = { Accept: 'application/json', Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json', 'X-Connector-Timestamp': String(Math.floor(Date.now() / 1000)), 'X-Connector-Nonce': randomUUID() };
        if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
        const response = await this.fetch(`${this.baseUrl}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
        const responseBody = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = responseBody?.message || responseBody?.error?.message || `HTTP ${response.status}`;
            throw new Error(`Laravel connector contract rejected the request: ${message}`);
        }
        return responseBody;
    }
}
