export class SimulatorAdapter {
    mode = 'simulator';
    label = 'სატესტო რეჟიმი (Simulator)';
    #latestVersions = new Map();
    #eventQueues = new Map();

    async heartbeat() {
        return { status: 'online', capabilities: { simulated: true, max_users: 50_000, max_cards: 50_000, max_event_log: 200_000 } };
    }
    async applyCommand(deviceId, command) {
        const target = `${deviceId}:${command.targetEntityType || 'none'}:${command.targetEntityId || 'none'}`;
        const latestVersion = this.#latestVersions.get(target) || 0;
        if (command.commandVersion < latestVersion) return { result: 'failed', error: `stale command v${command.commandVersion}; v${latestVersion} is already applied` };
        this.#latestVersions.set(target, command.commandVersion);
        return { result: 'succeeded', error: null };
    }
    async pullEvents(deviceId, checkpoint, limit) {
        const queue = this.#eventQueues.get(deviceId) || []; const batch = queue.splice(0, limit);
        this.#eventQueues.set(deviceId, queue); return batch;
    }
    queueEvent(deviceId, event) {
        const queue = this.#eventQueues.get(deviceId) || []; queue.push({ ...event, ingestion_source: 'simulator' }); this.#eventQueues.set(deviceId, queue);
    }
}
