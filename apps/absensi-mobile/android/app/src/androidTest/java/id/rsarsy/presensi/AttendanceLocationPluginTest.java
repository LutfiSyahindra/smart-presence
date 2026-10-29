package id.rsarsy.presensi;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import android.location.Location;
import android.location.LocationManager;
import androidx.test.filters.SdkSuppress;
import androidx.test.ext.junit.runners.AndroidJUnit4;
import org.junit.Test;
import org.junit.runner.RunWith;

@RunWith(AndroidJUnit4.class)
public class AttendanceLocationPluginTest {
    @Test
    public void ordinaryLocationIsNotMocked() {
        Location location = new Location(LocationManager.GPS_PROVIDER);
        assertFalse(AttendanceLocationPlugin.isMockLocation(location));
    }

    @Test
    @SdkSuppress(minSdkVersion = 31)
    public void mockFlagIsDetectedRegardlessOfAccuracyOrProvider() {
        for (String provider : new String[] { LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER, "fused" }) {
            Location location = new Location(provider);
            location.setLatitude(-6.2);
            location.setLongitude(106.8166667);
            location.setMock(true);
            for (float accuracy : new float[] { 1, 5000 }) {
                location.setAccuracy(accuracy);
                assertTrue(AttendanceLocationPlugin.isMockLocation(location));
            }
        }
    }

    private Location fix(String provider, long timestamp, float accuracy) {
        Location location = new Location(provider);
        location.setLatitude(-6.2);
        location.setLongitude(106.8166667);
        location.setElapsedRealtimeNanos(timestamp);
        location.setAccuracy(accuracy);
        return location;
    }

    @Test
    public void freshAccurateNetworkAndFusedFixesAreUsableIndoors() {
        for (String provider : new String[] { LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER, "fused" }) {
            assertTrue(AttendanceLocationPlugin.isUsableLocation(fix(provider, 2_000_000_000L, 50), 1_000_000_000L, 3_000_000_000L, 100));
        }
    }

    @Test
    public void staleFutureInaccurateAndInvalidFixesAreRejected() {
        assertFalse(AttendanceLocationPlugin.isUsableLocation(fix("network", 500, 10), 1000, 3000, 100));
        assertFalse(AttendanceLocationPlugin.isUsableLocation(fix("fused", 4000, 10), 1000, 3000, 100));
        assertFalse(AttendanceLocationPlugin.isUsableLocation(fix("gps", 2000, 10), 1000, 16_000_002_001L, 100));
        for (float accuracy : new float[] { -1, Float.NaN, Float.POSITIVE_INFINITY, 101 }) {
            assertFalse(AttendanceLocationPlugin.isUsableLocation(fix("network", 2000, accuracy), 1000, 3000, 100));
        }
        Location invalid = fix("network", 2000, 10);
        invalid.setLatitude(Double.NaN);
        assertFalse(AttendanceLocationPlugin.isUsableLocation(invalid, 1000, 3000, 100));
        assertFalse(AttendanceLocationPlugin.isUsableLocation(fix("network", 2000, 50), 1000, 3000, 40));
    }
}
