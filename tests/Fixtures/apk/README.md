# APK signature fixtures

These tiny APKs contain only a manifest and no executable application code.
They use an independent, disposable RSA test certificate, never the production key.
The private test key is not committed. Test signing certificate SHA-256:
`b95616f18b00329ef54ec81143fe782d8221e650155d7c1a3e8d68c288b7c12c`.

- `valid.apk`: package id.rsarsy.presensi, versionCode 42, versionName 2.0, minSdk 24, targetSdk 36.
- `unsigned.apk`: the same manifest before signing.
- `debug.apk`: signed, but application android:debuggable=true.
- `wrong-package.apk`: signed, package id.example.unrelated.

Generated with Android SDK 35.0.0 aapt (`package -M AndroidManifest.xml -I android-36/android.jar`)
and apksigner (`sign --ks <disposable-test-keystore>`). Fixtures exercise actual APK
signature verification and manifest extraction, alongside the mocked release workflow tests.
Integration tests skip only when the external SDK tools are not installed; the Docker image provides them.
