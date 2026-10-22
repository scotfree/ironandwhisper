"""A '&' on fire, Doom-fire style: heat climbs from the glyph's outline.

Run from the repo root (size, then optional seed; 3 made ampersand_fire.png):
    sim/.venv/bin/python misc/art/render_fire_ampersand.py misc/art/ampersand_fire.png 50 3
"""
import sys
import numpy as np
from PIL import Image, ImageDraw, ImageFont, ImageFilter

OUT, TARGET = sys.argv[1], int(sys.argv[2])
SEED = int(sys.argv[3]) if len(sys.argv) > 3 else 3
N = TARGET * 8
rng = np.random.default_rng(SEED)

font = ImageFont.truetype('fonts/UnifrakturCook-Bold.ttf', int(N * 1.0))
stroke = max(2, N // 45)
bb = font.getbbox('&', stroke_width=stroke)
w, h = bb[2] - bb[0], bb[3] - bb[1]
scale = min(1.0, (N * 0.86) / w, (N * 0.66) / h)              # glyph takes the lower two thirds
font = ImageFont.truetype('fonts/UnifrakturCook-Bold.ttf', int(N * scale))
bb = font.getbbox('&', stroke_width=stroke)
w, h = bb[2] - bb[0], bb[3] - bb[1]
gx, gy = (N - w) / 2 - bb[0], N * 0.98 - h - bb[1]
mask_img = Image.new('L', (N, N), 0)
ImageDraw.Draw(mask_img).text((gx, gy), '&', font=font, fill=255, stroke_width=stroke)

# Heat source: a ring just outside the glyph, so flames lick its edges.
source = np.asarray(mask_img.filter(ImageFilter.MaxFilter(2 * (N // 40) + 1)), dtype=float) / 255

# Columns cool at different rates: that is what makes separate tongues.
col = np.asarray(Image.fromarray((rng.random((1, 10)) * 255).astype(np.uint8)).resize((N, 1), Image.BICUBIC), dtype=float)[0] / 255
flame_len = N * (0.18 + 0.32 * col)                             # pixels of rise per column
heat = source.copy()
jitter = max(1, N // 130)
for y in range(N - 1, 0, -1):
    row = heat[y]
    shift = rng.integers(-jitter, jitter + 1)
    risen = np.roll(row, shift) - rng.random(N) * (2.0 / flame_len)
    heat[y - 1] = np.maximum(heat[y - 1], np.clip(risen, 0, 1))
heat = np.asarray(Image.fromarray((heat * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(N / 160)), dtype=float) / 255

stops = np.array([0.0, 0.15, 0.4, 0.7, 1.0])
colours = np.array([[60, 0, 0], [190, 25, 0], [255, 100, 0], [255, 190, 30], [255, 245, 190]], dtype=float)
rgb = np.stack([np.interp(heat, stops, colours[:, c]) for c in range(3)], axis=-1)
alpha = np.clip(heat * 2.2, 0, 1) * 255
fire = Image.fromarray(np.dstack([rgb, alpha]).astype(np.uint8))

glyph = Image.new('RGBA', (N, N), (0, 0, 0, 0))
ImageDraw.Draw(glyph).text((gx, gy), '&', font=font, fill=(255, 196, 60, 255), stroke_width=stroke, stroke_fill=(40, 6, 0, 255))
arr = np.asarray(glyph, dtype=float)
inner = np.asarray(Image.new('L', (N, N), 0), dtype=float)
inner_img = Image.new('L', (N, N), 0)
ImageDraw.Draw(inner_img).text((gx, gy), '&', font=font, fill=255)
inner = np.asarray(inner_img, dtype=float)[..., None] / 255
t = np.clip((np.linspace(0, 1, N)[:, None, None] - 0.45) * 1.8, 0, 1)   # white-hot towards the base
arr[..., :3] = arr[..., :3] * (1 - inner * t) + np.array([255, 248, 215]) * inner * t
glyph = Image.fromarray(arr.clip(0, 255).astype(np.uint8))

out = Image.alpha_composite(fire, glyph)
out.resize((TARGET, TARGET), Image.LANCZOS).save(OUT, optimize=True)
out.resize((400, 400), Image.LANCZOS).save(OUT.replace('.png', '_preview400.png'))
