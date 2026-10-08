package id.rsarsy.presensi;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebChromeClient;
import com.getcapacitor.BridgeWebViewClient;
import android.net.Uri;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

public class MainActivity extends BridgeActivity {
    private String presensiUrl;

    @Override
    protected void load() {
        registerPlugin(AttendanceLocationPlugin.class);
        super.load();
        final String origin = bridge.getServerUrl();
        if (origin == null) return;
        presensiUrl = origin + "/absensi/mobile";

        // Reuse Capacitor's Android runtime permission and file-picker handlers.
        // Only the configured frontend can request camera or GPS access.
        bridge.getWebView().setWebChromeClient(new BridgeWebChromeClient(bridge) {
            @Override
            public void onPermissionRequest(PermissionRequest request) {
                String[] resources = request.getResources();
                if (!sameOrigin(request.getOrigin().toString(), origin)
                    || resources.length != 1
                    || !PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(resources[0])) {
                    request.deny();
                    return;
                }
                super.onPermissionRequest(request);
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String requestedOrigin, GeolocationPermissions.Callback callback) {
                if (!sameOrigin(requestedOrigin, origin)) {
                    callback.invoke(requestedOrigin, false, false);
                    return;
                }
                super.onGeolocationPermissionsShowPrompt(requestedOrigin, callback);
            }
        });

        bridge.setWebViewClient(new BridgeWebViewClient(bridge) {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (request.isForMainFrame() && "rsarsy-retry://home".equals(request.getUrl().toString())) {
                    view.loadUrl(presensiUrl);
                    return true;
                }
                return super.shouldOverrideUrlLoading(view, request);
            }

            @Override
            public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse response) {
                // Keep Laravel's own validation/authorization/error responses.
                // The offline fallback is for connection failures only.
            }
        });

        // The protected home redirects guests to the existing auth/Login.vue.
        bridge.getWebView().loadUrl(presensiUrl);
    }

    private static boolean sameOrigin(String value, String expected) {
        Uri actual = Uri.parse(value);
        Uri configured = Uri.parse(expected);
        return actual.getScheme() != null && actual.getHost() != null
            && configured.getScheme().equalsIgnoreCase(actual.getScheme())
            && configured.getHost().equalsIgnoreCase(actual.getHost())
            && effectivePort(configured) == effectivePort(actual);
    }

    private static int effectivePort(Uri uri) {
        return uri.getPort() != -1 ? uri.getPort() : ("https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80);
    }
}
