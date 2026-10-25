package id.rsarsy.presensi;

import java.net.URI;
import java.net.URISyntaxException;

/** Exact origin checks shared by the remote wrapper and native permissions. */
final class PresensiOrigin {
    private PresensiOrigin() {}

    static boolean sameOrigin(String value, String expected) {
        if (value == null || expected == null) return false;
        try {
            URI actual = new URI(value);
            URI configured = new URI(expected);
            return actual.getUserInfo() == null && configured.getUserInfo() == null
                && actual.getHost() != null && configured.getHost() != null
                && actual.getScheme() != null && configured.getScheme() != null
                && configured.getScheme().equalsIgnoreCase(actual.getScheme())
                && configured.getHost().equalsIgnoreCase(actual.getHost())
                && effectivePort(configured) == effectivePort(actual);
        } catch (URISyntaxException exception) {
            return false;
        }
    }

    private static int effectivePort(URI uri) {
        return uri.getPort() != -1 ? uri.getPort() : ("https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80);
    }
}
