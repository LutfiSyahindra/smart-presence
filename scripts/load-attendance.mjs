import { readFile } from 'node:fs/promises';
import { basename, dirname, resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

// Use dedicated test accounts and photos on staging: each accepted POST is a real attendance request.
const [baseArgument, manifestArgument, concurrencyArgument = '30', action = 'clock-in'] = process.argv.slice(2);
if (!baseArgument || !manifestArgument || baseArgument === '--help') {
    console.log('Usage: node scripts/load-attendance.mjs <API base URL> <manifest.json> [concurrency=30] [clock-in|clock-out]');
    console.log('Manifest: [{"token":"test-account-token","photo":"selfie.jpg","latitude":-6.2,"longitude":106.8166667,"accuracy":8}]');
    process.exit(baseArgument === '--help' ? 0 : 1);
}

try {
    const base = new URL(baseArgument.replace(/\/$/, '') + '/');
    if (!['http:', 'https:'].includes(base.protocol) || !['clock-in', 'clock-out'].includes(action)) throw new Error('Invalid API URL or action.');
    const manifestPath = resolve(manifestArgument);
    const accounts = JSON.parse(await readFile(manifestPath, 'utf8'));
    const concurrency = Number(concurrencyArgument);
    if (!Array.isArray(accounts) || !accounts.length || !Number.isInteger(concurrency) || concurrency < 1)
        throw new Error('Provide accounts and a positive integer concurrency.');
    if (new Set(accounts.map((item) => item.token)).size !== accounts.length) throw new Error('Each test account must have a distinct token.');
    for (const item of accounts) {
        if (!item.token || !item.photo || !Number.isFinite(item.latitude) || !Number.isFinite(item.longitude))
            throw new Error('Each account needs a token, photo, latitude, and longitude.');
    }

    async function request(account, path, init = {}) {
        const response = await fetch(new URL(path, base), {
            ...init,
            headers: { Accept: 'application/json', Authorization: `Bearer ${account.token}` },
            signal: AbortSignal.timeout(30000),
        });
        const body = await response.json();
        if (!response.ok) throw new Error(`HTTP ${response.status}: ${body.message || 'request failed'}`);
        return body;
    }

    const accepted = [];
    const completed = [];
    const failures = [];
    const started = performance.now();
    let cursor = 0;
    let finished = 0;

    async function runAccount(account) {
        const startedAccount = performance.now();
        const context = (await request(account, `attendance/${action}`)).data;
        const challenge = context.location_challenge;
        const seconds = challenge.collection_seconds || 0;
        const startTime = challenge.server_time || Date.now();
        if (seconds) await delay(seconds * 1000);
        const form = new FormData();
        form.set('latitude', String(account.latitude));
        form.set('longitude', String(account.longitude));
        form.set('accuracy', String(account.accuracy || 8));
        form.set('location_token', challenge.token);
        form.set('location_source', 'browser');
        form.set('outside_radius_confirmed', account.outside_note ? '1' : '0');
        form.set('outside_radius_note', account.outside_note || '');
        const samples = Math.max(1, challenge.min_samples);
        for (let index = 0; index < samples; index++) {
            const prefix = `location_samples[${index}]`;
            form.set(`${prefix}[latitude]`, String(account.latitude));
            form.set(`${prefix}[longitude]`, String(account.longitude));
            form.set(`${prefix}[accuracy]`, String(account.accuracy || 8));
            form.set(`${prefix}[captured_at]`, String(Math.round(startTime + (seconds * 1000 * index) / Math.max(1, samples - 1))));
        }
        const photo = await readFile(resolve(dirname(manifestPath), account.photo));
        const mime = /\.png$/i.test(account.photo) ? 'image/png' : /\.webp$/i.test(account.photo) ? 'image/webp' : 'image/jpeg';
        form.set('photo', new Blob([photo], { type: mime }), basename(account.photo));
        const beforePost = performance.now();
        let status = (await request(account, `attendance/${action}`, { method: 'POST', body: form })).data;
        if (!status.id || !status.status) throw new Error('Queue response missing; enable ATTENDANCE_QUEUE_ENABLED.');
        accepted.push(performance.now() - beforePost);
        const deadline = performance.now() + 10 * 60 * 1000;
        while (['pending', 'processing'].includes(status.status)) {
            if (performance.now() > deadline) throw new Error('Verification still pending after ten minutes.');
            await delay(3000);
            status = (await request(account, `attendance-submissions/${status.id}`)).data;
        }
        if (status.status !== 'succeeded') throw new Error(status.message || 'Verification failed.');
        completed.push(performance.now() - startedAccount);
    }

    await Promise.all(
        Array.from({ length: Math.min(concurrency, accounts.length) }, async () => {
            while (cursor < accounts.length) {
                const account = accounts[cursor++];
                try {
                    await runAccount(account);
                } catch (error) {
                    failures.push(error instanceof Error ? error.message : 'Unknown failure');
                }
                finished++;
                if (finished % 10 === 0 || finished === accounts.length)
                    console.log(`Completed ${finished}/${accounts.length}; succeeded=${completed.length}, failed=${failures.length}`);
            }
        }),
    );

    function metrics(values) {
        const sorted = [...values].sort((a, b) => a - b);
        const percentile = (fraction) => (sorted.length ? Math.round(sorted[Math.max(0, Math.ceil(sorted.length * fraction) - 1)]) : null);
        return { count: values.length, p50_ms: percentile(0.5), p95_ms: percentile(0.95), max_ms: percentile(1) };
    }
    console.log(
        JSON.stringify(
            {
                accounts: accounts.length,
                concurrency,
                elapsed_seconds: Math.round((performance.now() - started) / 1000),
                acceptance: metrics(accepted),
                completion: metrics(completed),
                failures: failures.length,
                reasons: [...new Set(failures)].slice(0, 5),
            },
            null,
            2,
        ),
    );
    if (failures.length) process.exitCode = 1;
} catch (error) {
    console.error(error instanceof Error ? error.message : 'Load test failed.');
    process.exitCode = 1;
}
