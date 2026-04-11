#!/usr/bin/env python3
"""
Generate a corporate-clean app icon for Talkabiz:
- Lighter background (soft white-green gradient feel)
- Logo bigger (cropped to remove outer sparkles, then scaled up)
- No sparkle/star elements
- Clean, professional look for App Store / Meta review
"""
from PIL import Image, ImageDraw, ImageFilter
import os
import math

BRANDING_DIR = os.path.join(os.path.dirname(__file__), '..', 'assets', 'branding')
SOURCE = os.path.join(BRANDING_DIR, 'app_icon_clean.png')
OUTPUT = os.path.join(BRANDING_DIR, 'app_icon_corporate.png')


def remove_sparkles(img):
    """Remove small bright sparkle/star artifacts from the image.
    Sparkles are small clusters of very bright (white/cyan) pixels
    surrounded by different-colored pixels. We detect them and replace
    with the median of surrounding pixels."""
    px = img.load()
    w, h = img.size
    # Build a mask of sparkle pixels (bright white/cyan isolated spots)
    sparkle_mask = [[False] * w for _ in range(h)]

    for y in range(2, h - 2):
        for x in range(2, w - 2):
            r, g, b, a = px[x, y]
            if a < 100:
                continue
            brightness = (r + g + b) / 3
            # Sparkle pixels are very bright (white or light cyan)
            is_bright = brightness > 220 and min(r, g, b) > 180
            if not is_bright:
                continue
            # Check if it's an isolated bright region (sparkle)
            # by comparing with a ring of pixels further out
            ring_brightness = []
            for dy in range(-3, 4):
                for dx in range(-3, 4):
                    if abs(dx) <= 1 and abs(dy) <= 1:
                        continue
                    nx, ny = x + dx, y + dy
                    if 0 <= nx < w and 0 <= ny < h:
                        nr, ng, nb, na = px[nx, ny]
                        if na > 100:
                            ring_brightness.append((nr + ng + nb) / 3)
            if ring_brightness:
                avg_ring = sum(ring_brightness) / len(ring_brightness)
                # If center is much brighter than surroundings, it's a sparkle
                if brightness - avg_ring > 60:
                    sparkle_mask[y][x] = True

    # Now paint over sparkle pixels with median of non-sparkle neighbors
    for y in range(h):
        for x in range(w):
            if not sparkle_mask[y][x]:
                continue
            neighbors = []
            for dy in range(-4, 5):
                for dx in range(-4, 5):
                    nx, ny = x + dx, y + dy
                    if 0 <= nx < w and 0 <= ny < h and not sparkle_mask[ny][nx]:
                        neighbors.append(px[nx, ny])
            if neighbors:
                neighbors.sort(key=lambda c: (c[0] + c[1] + c[2]) / 3)
                mid = neighbors[len(neighbors) // 2]
                px[x, y] = mid

    return img


def create_corporate_icon():
    src = Image.open(SOURCE).convert('RGBA')
    w, h = src.size  # 1024x1024

    # Step 1: Crop the center area to remove outer sparkle elements
    margin = int(w * 0.10)
    crop_box = (margin, margin, w - margin, h - margin)
    logo = src.crop(crop_box)

    # Step 1.5: Remove sparkle/star elements from inside the logo
    print('Removing sparkles...')
    logo = remove_sparkles(logo)

    # Step 2: Create the lighter background
    canvas_size = 1024
    canvas = Image.new('RGBA', (canvas_size, canvas_size))
    draw = ImageDraw.Draw(canvas)

    # Subtle gradient: very light green to very light blue
    top_color = (235, 248, 236)      # Very light green
    bottom_color = (230, 244, 252)   # Very light blue
    for y in range(canvas_size):
        ratio = y / canvas_size
        r = int(top_color[0] + (bottom_color[0] - top_color[0]) * ratio)
        g = int(top_color[1] + (bottom_color[1] - top_color[1]) * ratio)
        b = int(top_color[2] + (bottom_color[2] - top_color[2]) * ratio)
        draw.line([(0, y), (canvas_size, y)], fill=(r, g, b, 255))

    # Step 3: Scale logo bigger - fill about 85% of canvas
    logo_target = int(canvas_size * 0.85)
    logo = logo.resize((logo_target, logo_target), Image.LANCZOS)

    # Step 4: Remove near-white background from the cropped logo
    pixels = logo.load()
    lw, lh = logo.size
    for y in range(lh):
        for x in range(lw):
            r, g, b, a = pixels[x, y]
            if r > 220 and g > 230 and b > 220 and a > 200:
                lightness = (r + g + b) / 3
                if lightness > 242:
                    pixels[x, y] = (r, g, b, 0)
                elif lightness > 232:
                    fade = int(255 * (245 - lightness) / 13)
                    pixels[x, y] = (r, g, b, max(0, fade))

    # Step 5: Center logo on canvas
    offset_x = (canvas_size - logo_target) // 2
    offset_y = (canvas_size - logo_target) // 2
    canvas.paste(logo, (offset_x, offset_y), logo)

    # Step 6: Convert to RGB (iOS requires no alpha for app icons)
    final = Image.new('RGB', (canvas_size, canvas_size), (255, 255, 255))
    final.paste(canvas, (0, 0), canvas)

    final.save(OUTPUT, 'PNG', quality=95)
    print(f'Corporate icon saved to: {OUTPUT}')
    print(f'Size: {final.size}')

if __name__ == '__main__':
    create_corporate_icon()
