package id.rsarsy.presensi;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebChromeClient;
import com.getcapacitor.BridgeWebViewClient;
import android.net.Uri;
import android.app.AlertDialog;
import android.graphics.Bitmap;
import android.net.http.SslError;
import android.util.Log;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import androidx.activity.OnBackPressedCallback;
import androidx.webkit.WebViewFeature;

public class MainActivity extends BridgeActivity {
    private String presensiUrl;

    @Override
    protected void load() {
        registerPlugin(AttendanceLocationPlugin.class);
        registerPlugin(AppUpdatesPlugin.class);
        super.load();
        final boolean release = !BuildConfig.DEBUG;
        final String origin = release ? BuildConfig.PRESENSI_ORIGIN : bridge.getServerUrl();
        if (origin == null) return;
        presensiUrl = origin + "/absensi/mobile";

        if (release) {
            WebView.setWebContentsDebuggingEnabled(false);
            // Never expose the unrestricted JavascriptInterface fallback to remote
            // pages. The modern bridge checks both the origin and the main frame.
            bridge.getWebView().removeJavascriptInterface("androidBridge");
            if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)
                || !WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
                bridge.getWebView().stopLoading();
                new AlertDialog.Builder(this)
                    .setTitle("Perbarui Android System WebView")
                    .setMessage("Perbarui Android System WebView melalui Play Store, lalu buka kembali aplikasi presensi.")
                    .setPositiveButton("Tutup", (dialog, which) -> finish())
                    .setCancelable(false)
                    .show();
                return;
            }
        }

        // Android's button and edge gesture must traverse the frontend history.
        // BridgeActivity alone has no back handler when the App plugin is absent.
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                WebView view = bridge.getWebView();
                String url = view.getUrl();
                if (url == null || !sameOrigin(url, origin)) {
                    moveTaskToBack(true);
                    return;
                }
                view.evaluateJavascript(
                    "!window.dispatchEvent(new Event('rsarsy:back', { cancelable: true }))",
                    handled -> {
                        if ("true".equals(handled)) return;
                        // Also support a cached/older frontend or a page still loading.
                        String path = Uri.parse(url).getPath();
                        if (path != null && path.startsWith("/absensi/mobile/")) {
                            if (view.canGoBack()) view.goBack();
                            else view.loadUrl(presensiUrl);
                        } else {
                            moveTaskToBack(true);
                        }
                    }
                );
            }
        });

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
            private String mainDocumentUrl = presensiUrl;
            private boolean showingConnectionError;

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                super.onPageStarted(view, url, favicon);
                if (sameOrigin(url, origin) && !Uri.parse(url).getPath().startsWith("/native/")) {
                    mainDocumentUrl = url;
                    showingConnectionError = false;
                }
            }

            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                // Capacitor's hostname restricts the document-start script and
                // message bridge. Real application pages use Android's HTTPS
                // network stack, while /native/ remains a bundled offline asset.
                String path = request.getUrl().getPath();
                if (release && sameOrigin(request.getUrl().toString(), origin)
                    && path != null && !path.startsWith("/native/")) {
                    return null;
                }
                return super.shouldInterceptRequest(view, request);
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (request.isForMainFrame() && "rsarsy-retry://home".equals(request.getUrl().toString())) {
                    view.loadUrl(presensiUrl);
                    return true;
                }
                return super.shouldOverrideUrlLoading(view, request);
            }

            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.cancel();
                // This callback also covers fonts, map tiles and other resources.
                // A rejected resource must not replace a healthy application page.
                if (PresensiConnectionFailure.isMainDocument(error.getUrl(), mainDocumentUrl, origin)) {
                    showConnectionError(view, PresensiConnectionFailure.sslReason(error.getPrimaryError()), "SSL", error.getPrimaryError());
                }
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                // Handle the main document ourselves instead of Capacitor's generic
                // fallback, and never recurse when a bundled fallback asset fails.
                if (request.isForMainFrame()
                    && PresensiConnectionFailure.isMainDocument(request.getUrl().toString(), mainDocumentUrl, origin)) {
                    showConnectionError(view, PresensiConnectionFailure.webReason(error.getErrorCode()), "WEB", error.getErrorCode());
                }
            }

            private void showConnectionError(WebView view, String reason, String source, int code) {
                if (showingConnectionError || bridge.getErrorUrl() == null) return;
                showingConnectionError = true;
                // Avoid logging paths or query strings, which can contain account tokens.
                Log.w("PresensiConnection", "Connection failed: " + source + " " + code + " " + reason
                    + " host=" + Uri.parse(origin).getHost() + " app=" + BuildConfig.VERSION_NAME);
                String errorUrl = Uri.parse(bridge.getErrorUrl()).buildUpon()
                    .appendQueryParameter("reason", reason)
                    .appendQueryParameter("code", source + " " + code)
                    .build().toString();
                view.loadUrl(errorUrl);
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
        return PresensiOrigin.sameOrigin(value, expected);
    }
}
