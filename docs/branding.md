# Logo ARSY Presensi

`public/images/arsy-presensi-logo.png` adalah gambar transparan asli yang diberikan
pemilik aplikasi. Warna, bentuk, dan tulisan dipertahankan. `arsy-presensi-mark.png`
berisi simbol di atas tulisan untuk ruang kecil, favicon, dan ikon launcher.

Frontend Laravel/Vue memakai `AppLogoIcon.vue`; gunakan `variant="full"` untuk logo
lengkap. Ukuran gambar memakai `object-fit: contain` agar proporsinya tetap terjaga.
Frontend ini juga ditampilkan oleh wrapper Android dan iOS.

Untuk menghasilkan ulang semua aset, gunakan Python dengan Pillow:

```powershell
python scripts/generate-brand-assets.py
```

Generator memperbarui favicon, ikon PWA termasuk maskable dan Apple touch icon,
ikon launcher Android di seluruh density, splash Android/iOS, ikon iOS 1024 px,
serta salinan logo untuk prototipe dan halaman offline. Ikon Android adaptif
menempatkan simbol di area aman agar tidak terpotong mask launcher. Ikon iOS
memakai latar putih tanpa alpha. URL ikon lama tetap menyajikan gambar baru.

Notifikasi web/admin dan PWA mobile memakai logo berwarna
`/icons/arsy-presensi-192.png`. Badge kecil memakai
`/icons/arsy-presensi-badge-96.png`, siluet putih transparan dari simbol logo yang
sama. Android native memakai siluet tersebut pada resource `ic_stat_notification`
di lima density, dengan warna aksen hijau aplikasi. Ikon notifikasi kecil Android
harus monokrom; logo berwarna dengan latar penuh akan terlihat seperti kotak.
iOS native mengambil logo dari AppIcon aplikasi yang terpasang.

Untuk menghasilkan ulang hanya badge dan ikon notifikasi:

```powershell
python scripts/generate-brand-assets.py --notifications-only
```

Setelah perubahan frontend, jalankan `npm run build`. Perubahan ikon native dan
splash memerlukan build serta pemasangan ulang aplikasi Android; build iOS
memerlukan macOS/Xcode. PWA yang sudah terpasang dapat menyimpan ikon lama sampai
browser memperbarui manifest atau aplikasi dipasang ulang.
