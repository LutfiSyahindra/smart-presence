import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { createServer } from 'node:net';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

test('a committed bulk database change reaches a private mobile WebSocket through real Reverb', { timeout: 20000 }, async () => {
    const allocator = createServer();
    allocator.listen(0, '127.0.0.1');
    await once(allocator, 'listening');
    const port = allocator.address().port;
    await new Promise((resolve) => allocator.close(resolve));
    const cwd = fileURLToPath(new URL('../../', import.meta.url));
    const env = {
        ...process.env,
        APP_ENV: 'testing',
        APP_KEY: `base64:${Buffer.alloc(32, 1).toString('base64')}`,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: ':memory:',
        CACHE_STORE: 'array',
        SESSION_DRIVER: 'array',
        BROADCAST_CONNECTION: 'reverb',
        MOBILE_REALTIME_ENABLED: 'true',
        REVERB_APP_ID: 'integration-app',
        REVERB_APP_KEY: 'integration-key',
        REVERB_APP_SECRET: 'integration-secret',
        REVERB_HOST: '127.0.0.1',
        REVERB_PORT: String(port),
        REVERB_SCHEME: 'http',
        REVERB_ALLOWED_ORIGINS: '*',
    };
    const php = process.env.PHP_BINARY || 'php';
    const server = spawn(php, ['artisan', 'reverb:start', '--host=127.0.0.1', `--port=${port}`, '--no-interaction'], { cwd, env, windowsHide: true });
    let output = '';
    server.stdout.on('data', (chunk) => (output += chunk));
    server.stderr.on('data', (chunk) => (output += chunk));
    let socket;
    try {
        await new Promise((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error(`Reverb startup timed out: ${output}`)), 10000);
            const ready = () => {
                if (output.includes('Starting server')) {
                    clearTimeout(timeout);
                    resolve();
                }
            };
            server.stdout.on('data', ready);
            server.once('exit', (code) => {
                clearTimeout(timeout);
                reject(new Error(`Reverb exited ${code}: ${output}`));
            });
        });
        socket = new WebSocket(`ws://127.0.0.1:${port}/app/integration-key?protocol=7&client=integration&version=1`);
        const received = [];
        const waiters = [];
        socket.addEventListener('message', ({ data }) => {
            const message = JSON.parse(data);
            received.push(message);
            for (const waiter of [...waiters]) {
                if (message.event === waiter.event) {
                    waiters.splice(waiters.indexOf(waiter), 1);
                    waiter.resolve(message);
                }
            }
        });
        const waitFor = (event) =>
            new Promise((resolve, reject) => {
                const deadline = setTimeout(() => reject(new Error(`Timed out waiting for ${event}`)), 5000);
                const finish = (value) => {
                    clearTimeout(deadline);
                    resolve(value);
                };
                const existing = received.find((message) => message.event === event);
                if (existing) finish(existing);
                else waiters.push({ event, resolve: finish });
            });
        const connected = await waitFor('pusher:connection_established');
        const { socket_id } = JSON.parse(connected.data);
        const channel = 'private-mobile.data';
        const signature = createHmac('sha256', 'integration-secret').update(`${socket_id}:${channel}`).digest('hex');
        socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: `integration-key:${signature}` } }));
        await waitFor('pusher_internal:subscription_succeeded');
        const publisher = spawn(php, ['-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3', 'tests/Fixtures/publish-mobile-change.php'], {
            cwd,
            env,
            windowsHide: true,
        });
        let error = '';
        publisher.stderr.on('data', (chunk) => (error += chunk));
        const [code] = await once(publisher, 'exit');
        assert.equal(code, 0, error);
        const event = await waitFor('mobile.data.changed');
        const payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
        assert.deepEqual(Object.keys(payload), ['version']);
        assert.match(payload.version, /^[0-9A-Z]{26}$/);
        assert.equal(JSON.stringify(event).includes('private value'), false);
        assert.equal(received.filter((message) => message.event === 'mobile.data.changed').length, 1);
    } finally {
        socket?.close();
        if (server.exitCode === null) {
            server.kill();
            await once(server, 'exit');
        }
    }
});
