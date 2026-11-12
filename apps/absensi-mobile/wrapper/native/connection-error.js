(() => {
    const messages = new Map([
        ['dns', ['Alamat server belum ditemukan', 'HP belum berhasil menerjemahkan alamat server melalui DNS. Coba beralih antara Wi-Fi dan data seluler. Jika tetap gagal, sampaikan kode di bawah kepada admin.']],
        ['connect', ['Koneksi ke server belum berhasil', 'Server presensi belum menerima koneksi dari HP ini. Coba kembali atau beralih antara Wi-Fi dan data seluler. Jika tetap gagal, hubungi admin.']],
        ['timeout', ['Server belum merespons', 'Waktu tunggu koneksi ke server habis. Tunggu beberapa saat lalu coba kembali. Jika berulang, sampaikan kode di bawah kepada admin.']],
        ['tls', ['Koneksi HTTPS belum berhasil', 'Koneksi aman ke server belum dapat dibuat. Perbarui Android System WebView dan sistem Android. Jika tetap gagal, minta admin memeriksa konfigurasi HTTPS server.']],
        ['certificate', ['Sertifikat HTTPS belum dapat diverifikasi', 'HP belum dapat memverifikasi identitas server. Perbarui Android System WebView dan sistem Android. Jika tetap gagal, minta admin memeriksa rantai sertifikat HTTPS server.']],
        ['certificate-date', ['Tanggal atau sertifikat perlu diperiksa', 'Aktifkan tanggal dan waktu otomatis pada HP. Jika tetap gagal, minta admin memeriksa masa berlaku sertifikat HTTPS server.']],
    ]);
    const params = new URLSearchParams(window.location.search);
    const reason = params.get('reason');
    const message = messages.get(reason);
    if (message) {
        document.getElementById('error-title').textContent = message[0];
        document.getElementById('error-message').textContent = message[1];
    }
    const code = params.get('code');
    if ((message || reason === 'connection') && /^(WEB -\d{1,3}|SSL \d)$/.test(code || '')) {
        const element = document.getElementById('error-code');
        element.textContent = `Kode bantuan: ${code}`;
        element.hidden = false;
    }
})();
