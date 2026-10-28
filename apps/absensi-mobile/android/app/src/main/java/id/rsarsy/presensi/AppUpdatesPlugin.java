package id.rsarsy.presensi;

import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.content.pm.Signature;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import androidx.activity.result.ActivityResult;
import androidx.core.content.FileProvider;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.ActivityCallback;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.Arrays;
import java.util.HashSet;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONObject;

@CapacitorPlugin(name = "AppUpdates")
public class AppUpdatesPlugin extends Plugin {
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private volatile HttpURLConnection connection;
    private volatile boolean destroyed;
    private String stage = "idle";
    private int releaseId;
    private long downloaded;
    private long total;
    private String error = "";
    private JSONObject prepared;

    @PluginMethod
    public void getInfo(PluginCall call) {
        JSObject info = new JSObject();
        info.put("version_code", BuildConfig.VERSION_CODE);
        info.put("version_name", BuildConfig.VERSION_NAME);
        info.put("sdk_int", Build.VERSION.SDK_INT);
        info.put("in_app_updates", true);
        call.resolve(info);
    }

    @PluginMethod
    public synchronized void getUpdateState(PluginCall call) {
        JSObject result = new JSObject();
        result.put("stage", stage);
        result.put("release_id", releaseId);
        result.put("downloaded_bytes", downloaded);
        result.put("total_bytes", total);
        result.put("error", error);
        call.resolve(result);
    }

    @PluginMethod
    public synchronized void downloadUpdate(PluginCall call) {
        Integer requested = call.getInt("releaseId");
        if (requested == null || requested < 1) {
            call.reject("Rilis APK tidak valid.");
            return;
        }
        if (busy()) {
            call.reject("Pembaruan sedang diproses. Tunggu sebentar.");
            return;
        }
        releaseId = requested;
        downloaded = 0;
        total = 0;
        error = "";
        prepared = null;
        stage = "checking";
        worker.execute(() -> {
            File partial = new File(updateDirectory(), "update.part");
            try {
                JSONObject release = activeRelease(requested);
                long size = release.getLong("size_bytes");
                synchronized (this) { total = size; stage = "downloading"; }
                HttpURLConnection request = connect("/mobile-app/android/releases/" + requested + "/download");
                try (InputStream input = request.getInputStream(); OutputStream output = new FileOutputStream(partial)) {
                    AppUpdateTransfer.copy(input, output, size, release.getString("sha256"), bytes -> {
                        synchronized (this) { downloaded = bytes; }
                    });
                } finally { disconnect(); }
                synchronized (this) { stage = "verifying"; }
                verifyIdentity(partial, release);
                File apk = apkFile();
                if (apk.exists() && !apk.delete()) throw new IOException("Berkas pembaruan sebelumnya belum dapat diganti.");
                if (!partial.renameTo(apk)) throw new IOException("Berkas pembaruan belum dapat disimpan.");
                synchronized (this) { prepared = release; stage = "ready"; }
            } catch (Exception exception) {
                partial.delete();
                fail(exception);
            }
        });
        call.resolve();
    }

    @PluginMethod
    public synchronized void installUpdate(PluginCall call) {
        Integer requested = call.getInt("releaseId");
        if (requested == null || requested != releaseId || prepared == null || !"ready".equals(stage)) {
            call.reject("Unduh pembaruan terlebih dahulu.");
            return;
        }
        stage = "verifying";
        error = "";
        worker.execute(() -> {
            try {
                // A withdrawn/replaced release must not be installed from an old dialog.
                JSONObject active = activeRelease(requested);
                if (!active.getString("sha256").equals(prepared.getString("sha256"))) {
                    throw new IOException("Rilis telah berubah. Periksa pembaruan kembali.");
                }
                try (InputStream input = new FileInputStream(apkFile())) {
                    AppUpdateTransfer.copy(input, new OutputStream() {
                        @Override public void write(int value) {}
                        @Override public void write(byte[] value, int offset, int count) {}
                    }, active.getLong("size_bytes"), active.getString("sha256"), bytes -> {});
                }
                verifyIdentity(apkFile(), active);
                if (destroyed) return;
                getActivity().runOnUiThread(() -> requestInstall(call));
            } catch (Exception exception) {
                fail(exception);
                call.reject(message(exception), exception);
            }
        });
    }

    private void requestInstall(PluginCall call) {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !getContext().getPackageManager().canRequestPackageInstalls()) {
                synchronized (this) { stage = "permission"; }
                Intent settings = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getContext().getPackageName()));
                startActivityForResult(call, settings, "installPermissionResult");
            } else {
                launchInstaller(call);
            }
        } catch (Exception exception) {
            fail(exception);
            call.reject("Pengaturan pemasangan belum dapat dibuka.", exception);
        }
    }

    @ActivityCallback
    private void installPermissionResult(PluginCall call, ActivityResult result) {
        if (call == null) { synchronized (this) { stage = "ready"; } return; }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !getContext().getPackageManager().canRequestPackageInstalls()) {
            synchronized (this) { stage = "ready"; }
            call.reject("Izin belum diaktifkan. Ketuk Pasang pembaruan, lalu aktifkan Izinkan dari sumber ini.");
            return;
        }
        launchInstaller(call);
    }

    private void launchInstaller(PluginCall call) {
        try {
            Uri uri = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", apkFile());
            Intent intent = new Intent(Intent.ACTION_VIEW);
            intent.setDataAndType(uri, "application/vnd.android.package-archive");
            intent.setClipData(ClipData.newRawUri("Pembaruan ARSY Presensi", uri));
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            synchronized (this) { stage = "installing"; }
            startActivityForResult(call, intent, "installerResult");
        } catch (Exception exception) {
            synchronized (this) { stage = "ready"; }
            call.reject("Pemasangan belum dapat dibuka. Coba kembali.", exception);
        }
    }

    @ActivityCallback
    private synchronized void installerResult(PluginCall call, ActivityResult result) {
        // A successful self-update terminates this process. Returning here lets a
        // user retry after cancelling Android's confirmation without downloading again.
        stage = prepared == null ? "idle" : "ready";
        if (call != null) call.resolve();
    }

    private JSONObject activeRelease(int requested) throws Exception {
        HttpURLConnection request = connect("/mobile-app/android/release?version_code=" + BuildConfig.VERSION_CODE + "&sdk_int=" + Build.VERSION.SDK_INT);
        try (InputStream input = request.getInputStream(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096];
            int count;
            while ((count = input.read(buffer)) != -1) {
                if (output.size() + count > 65536) throw new IOException("Informasi rilis terlalu besar.");
                output.write(buffer, 0, count);
            }
            JSONObject status = new JSONObject(output.toString("UTF-8"));
            JSONObject release = status.optJSONObject("release");
            if (!status.optBoolean("update_available") || !status.optBoolean("compatible") || release == null
                || release.getInt("id") != requested || release.getInt("version_code") <= BuildConfig.VERSION_CODE
                || release.getLong("size_bytes") < 1 || release.getLong("size_bytes") > AppUpdateTransfer.MAX_BYTES
                || !release.getString("sha256").matches("[a-fA-F0-9]{64}")) {
                throw new IOException("Rilis ini sudah tidak tersedia atau tidak cocok. Periksa pembaruan kembali.");
            }
            return release;
        } finally { disconnect(); }
    }

    private HttpURLConnection connect(String path) throws IOException {
        String origin = BuildConfig.DEBUG ? getBridge().getServerUrl() : BuildConfig.PRESENSI_ORIGIN;
        if (origin == null || (!BuildConfig.DEBUG && !origin.startsWith("https://"))) throw new IOException("Alamat pembaruan tidak tersedia.");
        HttpURLConnection request = (HttpURLConnection) new URL(origin + path).openConnection();
        connection = request;
        request.setInstanceFollowRedirects(false);
        request.setConnectTimeout(15000);
        request.setReadTimeout(30000);
        request.setUseCaches(false);
        request.setRequestProperty("Accept", path.contains("/download") ? "application/vnd.android.package-archive" : "application/json");
        if (request.getResponseCode() != HttpURLConnection.HTTP_OK) {
            disconnect();
            throw new IOException("Server pembaruan belum dapat dihubungi atau rilis telah ditarik. Coba kembali.");
        }
        return request;
    }

    @SuppressWarnings("deprecation")
    private void verifyIdentity(File file, JSONObject release) throws Exception {
        PackageManager manager = getContext().getPackageManager();
        int flags = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P ? PackageManager.GET_SIGNING_CERTIFICATES : PackageManager.GET_SIGNATURES;
        PackageInfo candidate = manager.getPackageArchiveInfo(file.getAbsolutePath(), flags);
        PackageInfo installed = manager.getPackageInfo(getContext().getPackageName(), flags);
        if (candidate == null || !installed.packageName.equals(candidate.packageName)
            || (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P ? candidate.getLongVersionCode() : candidate.versionCode) != release.getInt("version_code")
            || candidate.applicationInfo == null || (candidate.applicationInfo.flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0
            || candidate.applicationInfo.minSdkVersion > Build.VERSION.SDK_INT
            || !new HashSet<>(Arrays.asList(signers(candidate))).equals(new HashSet<>(Arrays.asList(signers(installed))))) {
            throw new IOException("Identitas atau tanda tangan APK tidak cocok dengan aplikasi terpasang.");
        }
    }

    @SuppressWarnings("deprecation")
    private Signature[] signers(PackageInfo info) throws IOException {
        Signature[] values = Build.VERSION.SDK_INT >= Build.VERSION_CODES.P
            ? (info.signingInfo == null ? null : info.signingInfo.getApkContentsSigners()) : info.signatures;
        if (values == null || values.length == 0) throw new IOException("Tanda tangan APK tidak tersedia.");
        return values;
    }

    private File updateDirectory() {
        File directory = new File(getContext().getFilesDir(), "app-updates");
        directory.mkdirs();
        return directory;
    }

    private File apkFile() { return new File(updateDirectory(), "update.apk"); }

    private synchronized boolean busy() {
        return !"idle".equals(stage) && !"ready".equals(stage) && !"error".equals(stage);
    }

    private synchronized void fail(Exception exception) {
        disconnect();
        error = message(exception);
        stage = "error";
    }

    private String message(Exception exception) {
        return exception instanceof IOException ? exception.getMessage() : "Pembaruan belum berhasil. Periksa koneksi dan coba kembali.";
    }

    private void disconnect() {
        HttpURLConnection request = connection;
        connection = null;
        if (request != null) request.disconnect();
    }

    @Override
    protected void handleOnDestroy() {
        destroyed = true;
        disconnect();
        worker.shutdownNow();
    }

    @PluginMethod
    public void openDownload(PluginCall call) {
        Integer releaseId = call.getInt("releaseId");
        if (releaseId == null || releaseId <= 0) {
            call.reject("Rilis APK tidak valid.");
            return;
        }
        // Only this app's release endpoint can be opened, never an arbitrary URL.
        String origin = BuildConfig.DEBUG ? getBridge().getServerUrl() : BuildConfig.PRESENSI_ORIGIN;
        if (origin == null) {
            call.reject("Alamat unduhan tidak tersedia.");
            return;
        }
        Uri uri = Uri.parse(origin + "/mobile-app/android/releases/" + releaseId + "/download");
        getActivity().runOnUiThread(() -> {
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, uri);
                intent.addCategory(Intent.CATEGORY_BROWSABLE);
                getActivity().startActivity(intent);
                call.resolve();
            } catch (ActivityNotFoundException exception) {
                call.reject("Browser untuk membuka unduhan belum tersedia.", exception);
            }
        });
    }
}
