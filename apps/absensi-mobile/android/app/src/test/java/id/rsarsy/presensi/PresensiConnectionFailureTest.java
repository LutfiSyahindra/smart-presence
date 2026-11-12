package id.rsarsy.presensi;

import static org.junit.Assert.*;

import org.junit.Test;

public class PresensiConnectionFailureTest {
    private static final String ORIGIN = "https://absensi.rsarsylamongan.com";
    private static final String PAGE = ORIGIN + "/absensi/mobile?tab=home";

    @Test
    public void onlyTheCurrentMainDocumentCanReplaceThePage() {
        assertTrue(PresensiConnectionFailure.isMainDocument(PAGE, PAGE, ORIGIN));
        assertTrue(PresensiConnectionFailure.isMainDocument(PAGE, PAGE + "#section", ORIGIN));
        assertTrue(PresensiConnectionFailure.isMainDocument(ORIGIN + "/login", ORIGIN + "/login", ORIGIN));
        for (String resource : new String[] {
            "https://fonts.bunny.net/css?family=test",
            "https://a.tile.openstreetmap.org/1/1/1.png",
            ORIGIN + "/build/assets/app.js",
            ORIGIN + "/account/photo",
            ORIGIN + "/absensi/mobile?tab=other",
            "https://evil.test/absensi/mobile?tab=home",
            "http://absensi.rsarsylamongan.com/absensi/mobile?tab=home",
            "invalid", null,
        }) {
            assertFalse(resource, PresensiConnectionFailure.isMainDocument(resource, PAGE, ORIGIN));
        }
    }

    @Test
    public void fallbackFailuresCannotLoopAndAbsentPagesAreIgnored() {
        String fallback = ORIGIN + "/native/offline.html";
        assertFalse(PresensiConnectionFailure.isMainDocument(fallback, fallback, ORIGIN));
        assertFalse(PresensiConnectionFailure.isMainDocument(PAGE, null, ORIGIN));
        assertFalse(PresensiConnectionFailure.isMainDocument(PAGE, "about:blank", ORIGIN));
    }

    @Test
    public void distinguishesDnsConnectionTimeoutAndTlsFailures() {
        assertEquals("dns", PresensiConnectionFailure.webReason(-2));
        assertEquals("connect", PresensiConnectionFailure.webReason(-6));
        assertEquals("timeout", PresensiConnectionFailure.webReason(-8));
        assertEquals("tls", PresensiConnectionFailure.webReason(-11));
        assertEquals("connection", PresensiConnectionFailure.webReason(-1));
    }

    @Test
    public void certificateFailuresDoNotBlameInternetConnectivity() {
        assertEquals("certificate-date", PresensiConnectionFailure.sslReason(0));
        assertEquals("certificate-date", PresensiConnectionFailure.sslReason(1));
        assertEquals("certificate-date", PresensiConnectionFailure.sslReason(4));
        assertEquals("certificate", PresensiConnectionFailure.sslReason(2));
        assertEquals("certificate", PresensiConnectionFailure.sslReason(3));
        assertEquals("tls", PresensiConnectionFailure.sslReason(5));
    }
}
