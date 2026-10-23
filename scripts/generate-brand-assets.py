"""Generate web and native icons from the supplied ARSY Presensi logo.

Requires Pillow. Run from any directory with: python scripts/generate-brand-assets.py
The original transparent artwork is kept unchanged in public/images/.
"""

import argparse
import base64
from pathlib import Path
from shutil import copyfile

from PIL import Image, ImageDraw, ImageOps


ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "public"
MOBILE = ROOT / "apps/absensi-mobile"
RES = MOBILE / "android/app/src/main/res"
IOS = MOBILE / "ios/App/App/Assets.xcassets"
SOURCE = PUBLIC / "images/arsy-presensi-logo.png"
DENSITIES = {"mdpi": 1, "hdpi": 1.5, "xhdpi": 2, "xxhdpi": 3, "xxxhdpi": 4}


def save(image, path):
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path, optimize=True)


def centered(image, size, padding=0.0, background=(255, 255, 255, 0)):
    canvas = Image.new("RGBA", size, background)
    content = ImageOps.contain(
        image,
        (round(size[0] * (1 - 2 * padding)), round(size[1] * (1 - 2 * padding))),
        Image.Resampling.LANCZOS,
    )
    canvas.alpha_composite(content, ((size[0] - content.width) // 2, (size[1] - content.height) // 2))
    return canvas


def svg_alias(image_path, destination):
    # Embed the artwork so existing SVG URLs also work inside HTML <img> tags.
    data = base64.b64encode(image_path.read_bytes()).decode("ascii")
    destination.write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500">'
        '<title>ARSY Presensi</title>'
        f'<image width="500" height="500" href="data:image/png;base64,{data}"/>'
        '</svg>\n',
        encoding="utf-8",
    )


def notification_assets(mark):
    # Android small icons and Web Push badges use an alpha silhouette, without
    # the wordmark or an opaque background. Keep the supplied emblem's shape.
    silhouette = Image.new("RGBA", mark.size, (255, 255, 255, 0))
    silhouette.putalpha(mark.getchannel("A").point(lambda alpha: 255 if alpha >= 128 else 0))
    save(centered(silhouette, (96, 96), 1 / 12), PUBLIC / "icons/arsy-presensi-badge-96.png")
    for density, scale in DENSITIES.items():
        size = round(24 * scale)
        save(centered(silhouette, (size, size), 1 / 12), RES / f"drawable-{density}/ic_stat_notification.png")


def main(notifications_only=False):
    logo = Image.open(SOURCE).convert("RGBA")
    # Crop only the emblem above the wordmark, preserving the original pixels.
    # These proportional coordinates refer to the supplied 500 x 500 artwork.
    mark = logo.crop(tuple(round(value * logo.width) for value in (0.152, 0.032, 0.848, 0.728)))
    notification_assets(mark)
    if notifications_only:
        print("Generated ARSY Presensi Web Push badge and Android notification icons.")
        return
    mark_path = PUBLIC / "images/arsy-presensi-mark.png"
    save(centered(mark, (512, 512)), mark_path)

    for size in (32, 192, 512, 1024):
        save(centered(mark, (size, size), 0.08, "white").convert("RGB"), PUBLIC / f"icons/arsy-presensi-{size}.png")
    # Maskable artwork stays inside the central safe area for circular/squircle masks.
    save(centered(mark, (512, 512), 0.19, "white").convert("RGB"), PUBLIC / "icons/arsy-presensi-maskable-512.png")
    save(centered(mark, (180, 180), 0.08, "white").convert("RGB"), PUBLIC / "icons/apple-touch-icon.png")
    save(centered(mark, (64, 64)), PUBLIC / "assets/images/favicon.png")
    centered(mark, (64, 64)).save(PUBLIC / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48), (64, 64)])
    # Keep old installed clients and notification URLs on the new artwork, too.
    for size in (192, 1024):
        copyfile(PUBLIC / f"icons/arsy-presensi-{size}.png", PUBLIC / f"icons/absensi-{size}.png")
    svg_alias(SOURCE, PUBLIC / "logo.svg")

    for directory in (MOBILE / "public/images", MOBILE / "wrapper/images"):
        directory.mkdir(parents=True, exist_ok=True)
        copyfile(SOURCE, directory / SOURCE.name)
    svg_alias(mark_path, MOBILE / "public/app-icon.svg")

    for density, scale in DENSITIES.items():
        directory = RES / f"mipmap-{density}"
        size = round(48 * scale)
        icon = centered(mark, (size, size), 0.08, "white")
        save(icon.convert("RGB"), directory / "ic_launcher.png")
        circular = Image.new("RGBA", (size, size))
        # The launcher applies its own adaptive mask on Android 8+; this round
        # bitmap is the fallback for launchers using android:roundIcon directly.
        mask = Image.new("L", (size, size))
        ImageDraw.Draw(mask).ellipse((0, 0, size - 1, size - 1), fill=255)
        circular.paste(icon, mask=mask)
        save(circular, directory / "ic_launcher_round.png")
        adaptive_size = round(108 * scale)
        save(centered(mark, (adaptive_size, adaptive_size), 0.19), directory / "ic_launcher_foreground.png")

    for path in RES.glob("*/splash.png"):
        with Image.open(path) as previous:
            size = previous.size
        canvas = Image.new("RGB", size, "white").convert("RGBA")
        extent = round(min(size) * 0.46)
        artwork = centered(logo, (extent, extent))
        canvas.alpha_composite(artwork, ((size[0] - extent) // 2, (size[1] - extent) // 2))
        save(canvas.convert("RGB"), path)

    save(centered(mark, (1024, 1024), 0.08, "white").convert("RGB"), IOS / "AppIcon.appiconset/AppIcon-512@2x.png")
    for path in (IOS / "Splash.imageset").glob("*.png"):
        # Keep the logo within the visible center when iOS aspect-fills the image.
        save(centered(logo, (2732, 2732), 0.36, "white").convert("RGB"), path)

    print("Generated ARSY Presensi logos, favicons, PWA, Android, and iOS assets.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--notifications-only", action="store_true", help="Generate only Web Push and Android notification silhouettes.")
    main(notifications_only=parser.parse_args().notifications_only)
