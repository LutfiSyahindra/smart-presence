package id.rsarsy.presensi;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class PresensiOriginTest {
    private static final String ORIGIN = "https://absensi.rsarsylamongan.com";

    @Test
    public void acceptsOnlyTheConfiguredHttpsOrigin() {
        assertTrue(PresensiOrigin.sameOrigin(ORIGIN + "/absensi/mobile?tab=home", ORIGIN));
        assertTrue(PresensiOrigin.sameOrigin("https://ABSENSI.RSARSYLAMONGAN.COM:443/login", ORIGIN));
        for (String value : new String[] {
            "http://absensi.rsarsylamongan.com/login",
            "https://absensi.rsarsylamongan.com:8443/login",
            "https://absensi.rsarsylamongan.com.evil.test/login",
            "https://evil.test/absensi.rsarsylamongan.com",
            "https://absensi.rsarsylamongan.com@evil.test/",
            "https://user:password@absensi.rsarsylamongan.com/",
            "javascript:alert(1)", "file:///etc/passwd", "https://%61bsensi.rsarsylamongan.com/",
            "not a url", null,
        }) {
            assertFalse(value, PresensiOrigin.sameOrigin(value, ORIGIN));
        }
    }

    @Test
    public void keepsLoopbackDevelopmentPermissionsSeparate() {
        assertTrue(PresensiOrigin.sameOrigin("http://localhost:8600/clock-in", "http://localhost:8600"));
        assertFalse(PresensiOrigin.sameOrigin("http://localhost:8601/clock-in", "http://localhost:8600"));
        assertFalse(PresensiOrigin.sameOrigin("http://localhost:8600", ORIGIN));
    }
}
