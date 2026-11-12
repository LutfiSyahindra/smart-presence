package id.rsarsy.presensi;

import android.net.http.SslError;
import android.webkit.WebViewClient;
import java.net.URI;
import java.net.URISyntaxException;
import java.util.Objects;

/** Classifies failures without treating a blocked subresource as a failed page. */
final class PresensiConnectionFailure {
    private PresensiConnectionFailure() {}

    static boolean isMainDocument(String failedUrl, String documentUrl, String origin) {
        if (!PresensiOrigin.sameOrigin(failedUrl, origin)
            || !PresensiOrigin.sameOrigin(documentUrl, origin)) return false;
        try {
            URI failed = new URI(failedUrl);
            URI document = new URI(documentUrl);
            String path = failed.getRawPath();
            return !path.startsWith("/native/")
                && path.equals(document.getRawPath())
                && Objects.equals(failed.getRawQuery(), document.getRawQuery());
        } catch (URISyntaxException exception) {
            return false;
        }
    }

    static String webReason(int code) {
        switch (code) {
            case WebViewClient.ERROR_HOST_LOOKUP: return "dns";
            case WebViewClient.ERROR_CONNECT: return "connect";
            case WebViewClient.ERROR_TIMEOUT: return "timeout";
            case WebViewClient.ERROR_FAILED_SSL_HANDSHAKE: return "tls";
            default: return "connection";
        }
    }

    static String sslReason(int code) {
        switch (code) {
            case SslError.SSL_NOTYETVALID:
            case SslError.SSL_EXPIRED:
            case SslError.SSL_DATE_INVALID:
                return "certificate-date";
            case SslError.SSL_IDMISMATCH:
            case SslError.SSL_UNTRUSTED:
                return "certificate";
            default:
                return "tls";
        }
    }
}
