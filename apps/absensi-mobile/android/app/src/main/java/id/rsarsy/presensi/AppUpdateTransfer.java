package id.rsarsy.presensi;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.function.LongConsumer;

/** Bounds and verifies streamed APKs before they can reach Android's installer. */
final class AppUpdateTransfer {
    static final long MAX_BYTES = 100L * 1024 * 1024;

    private AppUpdateTransfer() {}

    static void copy(InputStream input, OutputStream output, long expectedSize, String sha256, LongConsumer progress)
        throws IOException {
        if (expectedSize < 1 || expectedSize > MAX_BYTES || sha256 == null || !sha256.matches("[a-fA-F0-9]{64}")) {
            throw new IOException("Informasi berkas pembaruan tidak valid.");
        }
        final MessageDigest digest;
        try {
            digest = MessageDigest.getInstance("SHA-256");
        } catch (NoSuchAlgorithmException exception) {
            throw new IOException(exception);
        }
        byte[] buffer = new byte[32 * 1024];
        long total = 0;
        int count;
        while ((count = input.read(buffer)) != -1) {
            if (Thread.currentThread().isInterrupted()) throw new IOException("Unduhan dihentikan.");
            total += count;
            if (total > expectedSize) throw new IOException("Ukuran unduhan tidak sesuai dengan rilis.");
            output.write(buffer, 0, count);
            digest.update(buffer, 0, count);
            progress.accept(total);
        }
        StringBuilder actual = new StringBuilder(64);
        for (byte value : digest.digest()) actual.append(String.format("%02x", value & 0xff));
        if (total != expectedSize || !actual.toString().equalsIgnoreCase(sha256)) {
            throw new IOException("Berkas pembaruan tidak lengkap atau rusak. Silakan unduh kembali.");
        }
    }
}
