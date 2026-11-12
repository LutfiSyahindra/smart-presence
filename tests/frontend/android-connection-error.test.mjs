import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const wrapper = new URL('../../apps/absensi-mobile/wrapper/', import.meta.url);
const source = await readFile(new URL('native/connection-error.js', wrapper), 'utf8');

function render(search) {
    const elements = {
        'error-title': { textContent: 'Server belum dapat dihubungi' },
        'error-message': { textContent: 'Pesan cadangan' },
        'error-code': { textContent: '', hidden: true },
    };
    vm.runInNewContext(source, {
        URLSearchParams,
        window: { location: { search } },
        document: { getElementById: (id) => elements[id] },
    });
    return elements;
}

test('both bundled pages load the shared diagnostic script from local assets', async () => {
    for (const [page, script] of [['offline.html', 'native/connection-error.js'], ['native/offline.html', 'connection-error.js']]) {
        const html = await readFile(new URL(page, wrapper), 'utf8');
        assert.ok(html.includes(`src="${script}"`));
        for (const id of ['error-title', 'error-message', 'error-code']) assert.ok(html.includes(`id="${id}"`));
        assert.ok(html.includes('href="rsarsy-retry://home"'));
    }
});

test('DNS, timeout and HTTPS failures give distinct recovery guidance', () => {
    for (const [reason, code, guidance] of [
        ['dns', 'WEB -2', /DNS/],
        ['connect', 'WEB -6', /menerima koneksi/],
        ['timeout', 'WEB -8', /Waktu tunggu/],
        ['tls', 'WEB -11', /HTTPS/],
        ['certificate', 'SSL 3', /sertifikat HTTPS/],
        ['certificate-date', 'SSL 1', /tanggal dan waktu otomatis/],
    ]) {
        const elements = render(`?reason=${reason}&code=${encodeURIComponent(code)}`);
        assert.match(elements['error-message'].textContent, guidance);
        assert.equal(elements['error-code'].textContent, `Kode bantuan: ${code}`);
        assert.equal(elements['error-code'].hidden, false);
    }
});

test('unknown reasons and untrusted diagnostic text keep the safe fallback', () => {
    for (const search of ['', '?reason=unknown&code=SSL+3', '?reason=__proto__&code=SSL+3', '?reason=certificate&code=%3Cscript%3E']) {
        const elements = render(search);
        assert.equal(elements['error-code'].hidden, true);
        assert.equal(elements['error-code'].textContent, '');
    }
    assert.equal(render('?reason=unknown')['error-title'].textContent, 'Server belum dapat dihubungi');
    assert.equal(render('?reason=connection&code=WEB+-1')['error-code'].hidden, false);
});
