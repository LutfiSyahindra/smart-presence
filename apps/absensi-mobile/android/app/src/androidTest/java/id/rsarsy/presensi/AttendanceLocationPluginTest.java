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
        for (String provider : new String[] { LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER }) {
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
}
