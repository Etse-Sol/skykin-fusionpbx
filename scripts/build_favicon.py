#!/usr/bin/env python3
"""Build crisp SkyKin favicons from the logo icon mark only."""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
LOGO = ROOT / "website" / "public" / "images" / "skykin_logo.png"
OUTS = [
    ROOT / "favicon.png",
    ROOT / "favicon.ico",
    ROOT / "themes" / "default" / "favicon.png",
    ROOT / "themes" / "skykin" / "images" / "favicon.png",
    ROOT / "app" / "agent_dashboard" / "assets" / "skykin-favicon.png",
    ROOT / "app" / "agent_dashboard" / "assets" / "apple-touch-icon.png",
    ROOT / "website" / "public" / "favicon.png",
    ROOT / "website" / "src" / "app" / "icon.png",
    ROOT / "website" / "src" / "app" / "apple-icon.png",
]

BRAND_BLUE = (0, 71, 171, 255)
ICON_CROP = (0, 8, 188, 161)  # left cloud mark from full logo


def _square_icon(src: Image.Image, size: int) -> Image.Image:
    icon = src.crop(ICON_CROP).convert("RGBA")
    w, h = icon.size
    side = max(w, h)
    canvas = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    canvas.paste(icon, ((side - w) // 2, (side - h) // 2), icon)

    # Rounded blue tile improves contrast in browser tabs.
    tile = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(tile)
    radius = max(4, size // 5)
    draw.rounded_rectangle((0, 0, size - 1, size - 1), radius=radius, fill=BRAND_BLUE)

    mark = canvas.resize((int(size * 0.72), int(size * 0.72)), Image.Resampling.LANCZOS)
    mx = (size - mark.size[0]) // 2
    my = (size - mark.size[1]) // 2
    tile.paste(mark, (mx, my), mark)
    return tile


def main() -> None:
    logo = Image.open(LOGO)
    sizes = [16, 32, 48, 64, 128, 180, 256]
    png32 = _square_icon(logo, 32)
    png180 = _square_icon(logo, 180)
    png256 = _square_icon(logo, 256)

    for path in OUTS:
        path.parent.mkdir(parents=True, exist_ok=True)
        if path.suffix == ".ico":
            ico_images = [_square_icon(logo, s) for s in (16, 32, 48)]
            ico_images[0].save(path, format="ICO", sizes=[(s, s) for s in (16, 32, 48)])
        elif "apple" in path.name:
            png180.save(path, format="PNG")
        else:
            (png256 if "icon.png" in path.name else png32).save(path, format="PNG")

    print("Wrote favicons:", ", ".join(str(p.relative_to(ROOT)) for p in OUTS))


if __name__ == "__main__":
    main()
