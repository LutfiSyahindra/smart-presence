package id.rsarsy.presensi;

import android.Manifest;
import android.content.Context;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

@CapacitorPlugin(
    name = "AttendanceLocation",
    permissions = {
        @Permission(alias = "location", strings = {
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        })
    }
)
public class AttendanceLocationPlugin extends Plugin {
    private final Handler handler = new Handler(Looper.getMainLooper());
    private LocationManager manager;
    private LocationListener listener;
    private Runnable deadline;
    private PluginCall pending;

    @PluginMethod
    public void getCurrentPosition(PluginCall call) {
        if (getPermissionState("location") != PermissionState.GRANTED) {
            requestPermissionForAlias("location", call, "locationPermissionResult");
            return;
        }
        handler.post(() -> start(call));
    }

    @PermissionCallback
    private void locationPermissionResult(PluginCall call) {
        if (getPermissionState("location") != PermissionState.GRANTED) {
            call.reject("Izinkan lokasi presisi untuk aplikasi ini melalui pengaturan perangkat.", "LOCATION_PERMISSION_DENIED");
            return;
        }
        handler.post(() -> start(call));
    }

    @SuppressWarnings("deprecation")
    static boolean isMockLocation(Location location) {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.S
            ? location.isMock()
            : location.isFromMockProvider();
    }

    private void start(PluginCall call) {
        if (pending != null) {
            call.reject("Pemeriksaan GPS masih berlangsung. Tunggu lalu coba kembali.", "LOCATION_BUSY");
            return;
        }
        manager = (LocationManager) getContext().getSystemService(Context.LOCATION_SERVICE);
        if (manager == null || !manager.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
            call.reject("GPS belum aktif. Aktifkan lokasi perangkat lalu coba kembali.", "LOCATION_DISABLED");
            return;
        }

        pending = call;
        final long startedAt = SystemClock.elapsedRealtimeNanos();
        listener = new LocationListener() {
            @Override
            public void onLocationChanged(Location location) {
                if (pending != call) return;
                // Check before accuracy/freshness filters: a poor mock fix is
                // still evidence of Fake GPS and must never be silently ignored.
                if (isMockLocation(location)) {
                    reject("Fake GPS terdeteksi. Nonaktifkan aplikasi lokasi palsu lalu ambil lokasi kembali.", "MOCK_LOCATION");
                    return;
                }
                if (!LocationManager.GPS_PROVIDER.equals(location.getProvider())
                    || location.getElapsedRealtimeNanos() < startedAt || !location.hasAccuracy()) return;

                JSObject result = new JSObject();
                result.put("latitude", location.getLatitude());
                result.put("longitude", location.getLongitude());
                result.put("accuracy", location.getAccuracy());
                result.put("captured_at", location.getTime());
                result.put("is_mock", false);
                PluginCall completed = pending;
                cleanup();
                completed.resolve(result);
            }

            @Override
            public void onProviderDisabled(String provider) {
                if (pending == call && LocationManager.GPS_PROVIDER.equals(provider)) {
                    reject("GPS dinonaktifkan. Aktifkan lokasi lalu ulangi verifikasi.", "LOCATION_DISABLED");
                }
            }

            @Override
            public void onProviderEnabled(String provider) {}

            @Override
            public void onStatusChanged(String provider, int status, Bundle extras) {}
        };
        deadline = () -> {
            if (pending == call) {
                reject("Pencarian GPS terlalu lama. Pindah ke area terbuka lalu coba kembali.", "LOCATION_TIMEOUT");
            }
        };
        handler.postDelayed(deadline, 15_000);
        try {
            // Only a fresh GPS fix supplies attendance coordinates. Observe
            // other active providers too, to reject their mock-location flags.
            manager.requestLocationUpdates(LocationManager.GPS_PROVIDER, 0, 0, listener, Looper.getMainLooper());
            for (String provider : manager.getProviders(true)) {
                if (!LocationManager.GPS_PROVIDER.equals(provider) && !LocationManager.PASSIVE_PROVIDER.equals(provider)) {
                    manager.requestLocationUpdates(provider, 0, 0, listener, Looper.getMainLooper());
                }
            }
        } catch (SecurityException exception) {
            reject("Izin lokasi presisi belum tersedia. Izinkan lokasi melalui pengaturan perangkat.", "LOCATION_PERMISSION_DENIED");
        } catch (RuntimeException exception) {
            reject("Pemeriksaan keamanan GPS gagal. Aktifkan GPS lalu coba kembali.", "LOCATION_UNAVAILABLE");
        }
    }

    private void reject(String message, String code) {
        PluginCall failed = pending;
        cleanup();
        if (failed != null) failed.reject(message, code);
    }

    private void cleanup() {
        if (deadline != null) handler.removeCallbacks(deadline);
        if (manager != null && listener != null) {
            try {
                manager.removeUpdates(listener);
            } catch (SecurityException ignored) {
                // Permission can be revoked while a request is in flight.
            }
        }
        pending = null;
        listener = null;
        deadline = null;
    }

    @Override
    protected void handleOnPause() {
        handler.post(() -> reject("Verifikasi GPS terhenti. Buka kembali aplikasi dan ulangi verifikasi.", "LOCATION_INTERRUPTED"));
    }

    @Override
    protected void handleOnDestroy() {
        handler.post(() -> reject("Verifikasi GPS terhenti. Buka kembali aplikasi dan ulangi verifikasi.", "LOCATION_INTERRUPTED"));
    }
}
