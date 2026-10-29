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
import java.util.ArrayList;
import java.util.List;
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
    private Runnable qualityDeadline;
    private PluginCall pending;
    private Location bestLocation;
    private boolean awaitingPermission;

    @PluginMethod
    public void getCurrentPosition(PluginCall call) {
        handler.post(() -> {
            if (pending != null) {
                call.reject("Pemeriksaan lokasi masih berlangsung. Tunggu lalu coba kembali.", "LOCATION_BUSY");
                return;
            }
            pending = call;
            if (getPermissionState("location") != PermissionState.GRANTED) {
                awaitingPermission = true;
                requestPermissionForAlias("location", call, "locationPermissionResult");
                return;
            }
            start(call);
        });
    }

    @PluginMethod
    public void cancelCurrentPosition(PluginCall call) {
        handler.post(() -> {
            String requestId = call.getString("requestId");
            if (pending != null && requestId != null && requestId.equals(pending.getString("requestId"))) {
                reject("Pencarian lokasi dibatalkan.", "LOCATION_INTERRUPTED");
            }
            call.resolve();
        });
    }

    @PermissionCallback
    private void locationPermissionResult(PluginCall call) {
        handler.post(() -> {
            if (pending != call) return;
            awaitingPermission = false;
            if (getPermissionState("location") != PermissionState.GRANTED) {
                reject("Izinkan lokasi presisi untuk aplikasi ini melalui pengaturan perangkat.", "LOCATION_PERMISSION_DENIED");
                return;
            }
            start(call);
        });
    }

    @SuppressWarnings("deprecation")
    static boolean isMockLocation(Location location) {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.S
            ? location.isMock()
            : location.isFromMockProvider();
    }

    static boolean isUsableLocation(Location location, long startedAt, long now, double maxAccuracy) {
        return location.getElapsedRealtimeNanos() >= startedAt
            && location.getElapsedRealtimeNanos() <= now
            && now - location.getElapsedRealtimeNanos() <= 15_000_000_000L
            && location.hasAccuracy()
            && Float.isFinite(location.getAccuracy()) && location.getAccuracy() >= 0
            && location.getAccuracy() <= maxAccuracy
            && Double.isFinite(location.getLatitude()) && Math.abs(location.getLatitude()) <= 90
            && Double.isFinite(location.getLongitude()) && Math.abs(location.getLongitude()) <= 180;
    }

    private List<String> enabledProviders() {
        List<String> providers = new ArrayList<>();
        if (manager != null) {
            for (String provider : manager.getProviders(true)) {
                if (!LocationManager.PASSIVE_PROVIDER.equals(provider)) providers.add(provider);
            }
        }
        return providers;
    }

    private void start(PluginCall call) {
        if (pending != call) return;
        manager = (LocationManager) getContext().getSystemService(Context.LOCATION_SERVICE);
        List<String> providers = enabledProviders();
        if (providers.isEmpty()) {
            reject("Lokasi perangkat belum aktif. Aktifkan layanan lokasi lalu coba kembali.", "LOCATION_DISABLED");
            return;
        }

        final long startedAt = SystemClock.elapsedRealtimeNanos();
        Double requestedAccuracy = call.getDouble("maxAccuracyMeters", 100.0);
        final double maxAccuracy = Double.isFinite(requestedAccuracy) && requestedAccuracy >= 10 ? requestedAccuracy : 100.0;
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
                if (!isUsableLocation(location, startedAt, SystemClock.elapsedRealtimeNanos(), maxAccuracy)) return;
                // Fused and Wi-Fi/network fixes can be more useful indoors than
                // satellite GPS. Keep the most accurate fresh, non-mock fix.
                if (bestLocation == null || location.getAccuracy() < bestLocation.getAccuracy()) bestLocation = new Location(location);
                if (bestLocation.getAccuracy() <= Math.min(25.0, maxAccuracy)) {
                    complete();
                } else if (qualityDeadline == null) {
                    qualityDeadline = () -> { if (pending == call) complete(); };
                    handler.postDelayed(qualityDeadline, 1_500);
                }
            }

            @Override
            public void onProviderDisabled(String provider) {
                if (pending == call && enabledProviders().isEmpty()) {
                    reject("Lokasi dinonaktifkan. Aktifkan layanan lokasi lalu ulangi verifikasi.", "LOCATION_DISABLED");
                }
            }

            @Override
            public void onProviderEnabled(String provider) {}

            @Override
            public void onStatusChanged(String provider, int status, Bundle extras) {}
        };
        deadline = () -> {
            if (pending == call) {
                if (bestLocation != null) complete();
                else reject("Lokasi akurat belum ditemukan. Aktifkan lokasi presisi dan Wi-Fi, lalu coba kembali.", "LOCATION_TIMEOUT");
            }
        };
        handler.postDelayed(deadline, 25_000);
        try {
            int subscriptions = 0;
            for (String provider : providers) {
                try {
                    manager.requestLocationUpdates(provider, 0, 0, listener, Looper.getMainLooper());
                    subscriptions++;
                } catch (IllegalArgumentException ignored) {
                    // A provider can disappear while the subscriptions start.
                }
            }
            if (subscriptions == 0) reject("Layanan lokasi belum tersedia. Aktifkan lokasi dan Wi-Fi lalu coba kembali.", "LOCATION_UNAVAILABLE");
        } catch (SecurityException exception) {
            reject("Izin lokasi presisi belum tersedia. Izinkan lokasi melalui pengaturan perangkat.", "LOCATION_PERMISSION_DENIED");
        } catch (RuntimeException exception) {
            reject("Pemeriksaan keamanan GPS gagal. Aktifkan GPS lalu coba kembali.", "LOCATION_UNAVAILABLE");
        }
    }

    private void complete() {
        if (pending == null || bestLocation == null) return;
        JSObject result = new JSObject();
        result.put("latitude", bestLocation.getLatitude());
        result.put("longitude", bestLocation.getLongitude());
        result.put("accuracy", bestLocation.getAccuracy());
        result.put("captured_at", bestLocation.getTime());
        result.put("is_mock", false);
        PluginCall completed = pending;
        cleanup();
        completed.resolve(result);
    }

    private void reject(String message, String code) {
        PluginCall failed = pending;
        cleanup();
        if (failed != null) failed.reject(message, code);
    }

    private void cleanup() {
        if (deadline != null) handler.removeCallbacks(deadline);
        if (qualityDeadline != null) handler.removeCallbacks(qualityDeadline);
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
        qualityDeadline = null;
        bestLocation = null;
        awaitingPermission = false;
    }

    @Override
    protected void handleOnPause() {
        handler.post(() -> {
            if (!awaitingPermission) reject("Verifikasi GPS terhenti. Buka kembali aplikasi dan ulangi verifikasi.", "LOCATION_INTERRUPTED");
        });
    }

    @Override
    protected void handleOnDestroy() {
        handler.post(() -> reject("Verifikasi GPS terhenti. Buka kembali aplikasi dan ulangi verifikasi.", "LOCATION_INTERRUPTED"));
    }
}
