import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm, rmdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const cwd = fileURLToPath(new URL('../../', import.meta.url));
const envValues = (text) =>
    Object.fromEntries(
        text
            .trim()
            .split(/\r?\n/)
            .map((line) => {
                const split = line.indexOf('=');
                return [line.slice(0, split), line.slice(split + 1)];
            }),
    );

for (const docker of [false, true]) {
    test(`realtime setup preserves existing secrets and is repeatable (${docker ? 'Docker' : 'local'})`, async () => {
        const directory = await mkdtemp(join(tmpdir(), 'mobile-realtime-'));
        const file = join(directory, '.env');
        try {
            await writeFile(file, 'APP_KEY=keep-existing-app-key\nAPP_URL=https://presensi.example.test\nBROADCAST_CONNECTION=log\n');
            const setup = () =>
                spawnSync('php', ['scripts/realtime-setup.php', `--env-file=${file}`, ...(docker ? ['--docker'] : [])], {
                    cwd,
                    encoding: 'utf8',
                    windowsHide: true,
                });
            const first = setup();
            assert.equal(first.status, 0, first.stderr);
            const before = envValues(await readFile(file, 'utf8'));
            assert.equal(before.APP_KEY, 'keep-existing-app-key');
            assert.equal(before.BROADCAST_CONNECTION, 'reverb');
            assert.equal(before.MOBILE_REALTIME_ENABLED, 'true');
            assert.equal(before.MOBILE_REALTIME_PORT, docker ? '' : '8080');
            assert.equal(before.REVERB_HOST, docker ? 'reverb' : '127.0.0.1');
            assert.match(before.REVERB_APP_SECRET, /^[a-f0-9]{64}$/);
            assert.equal(first.stdout.includes(before.REVERB_APP_SECRET), false);
            assert.equal(setup().status, 0);
            const after = envValues(await readFile(file, 'utf8'));
            assert.equal(after.REVERB_APP_SECRET, before.REVERB_APP_SECRET);
            assert.equal(after.REVERB_APP_KEY, before.REVERB_APP_KEY);
        } finally {
            await rm(file, { force: true });
            await rmdir(directory);
        }
    });
}
