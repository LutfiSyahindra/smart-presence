package id.rsarsy.presensi;

import static org.junit.Assert.*;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import org.junit.Test;

public class AppUpdateTransferTest {
    private static final byte[] CONTENT = "abc".getBytes(StandardCharsets.UTF_8);
    private static final String SHA256 = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad";

    @Test
    public void copiesVerifiedBytesAndReportsProgress() throws Exception {
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        List<Long> progress = new ArrayList<>();
        AppUpdateTransfer.copy(new ByteArrayInputStream(CONTENT), output, 3, SHA256.toUpperCase(), progress::add);
        assertArrayEquals(CONTENT, output.toByteArray());
        assertEquals(Long.valueOf(3), progress.get(progress.size() - 1));
    }

    @Test
    public void rejectsTruncatedOrChangedDownloads() {
        assertThrows(IOException.class, () -> copy("ab".getBytes(StandardCharsets.UTF_8), 3, SHA256));
        assertThrows(IOException.class, () -> copy("abd".getBytes(StandardCharsets.UTF_8), 3, SHA256));
        assertThrows(IOException.class, () -> copy(new byte[0], 3, SHA256));
    }

    @Test
    public void stopsOversizedResponsesBeforeWritingExtraBytes() {
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        assertThrows(IOException.class, () -> AppUpdateTransfer.copy(new ByteArrayInputStream(CONTENT), output, 2, SHA256, bytes -> {}));
        assertEquals(0, output.size());
    }

    @Test
    public void rejectsUnboundedOrInvalidMetadataBeforeReading() {
        assertThrows(IOException.class, () -> copy(CONTENT, 0, SHA256));
        assertThrows(IOException.class, () -> copy(CONTENT, AppUpdateTransfer.MAX_BYTES + 1, SHA256));
        assertThrows(IOException.class, () -> copy(CONTENT, 3, "invalid"));
        assertThrows(IOException.class, () -> copy(CONTENT, 3, null));
    }

    @Test
    public void interruptedDownloadsCannotBeAccepted() {
        Thread.currentThread().interrupt();
        try {
            assertThrows(IOException.class, () -> copy(CONTENT, 3, SHA256));
        } finally { Thread.interrupted(); }
    }

    private void copy(byte[] bytes, long size, String hash) throws IOException {
        AppUpdateTransfer.copy(new ByteArrayInputStream(bytes), new ByteArrayOutputStream(), size, hash, progress -> {});
    }
}
