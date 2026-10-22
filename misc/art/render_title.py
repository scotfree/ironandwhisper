"""Render title.png: 2000x2000, transparent, content inside y 500..1500.

BGA Metadata Manager title image. Run from the repo root:
    sim/.venv/bin/python misc/art/render_title.py misc/art/title.png
"""
import sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter

FONT = 'fonts/UnifrakturCook-Bold.ttf'
SIZE, TOP, BOTTOM, SIDE = 2000, 590, 1410, 140     # the glow spreads ~70px past the letters; BGA wants 500px clear
FILL, STROKE, GLOW = (244, 222, 168, 255), (22, 14, 8, 255), (0, 0, 0, 200)
LINES = [("Iron", 1.0), ("and", 0.42), ("Whispers", 1.0)]
GAP = 0.04                                             # line gap, as a fraction of the main size

def layout(main):
    fonts = [ImageFont.truetype(FONT, int(main * scale)) for _, scale in LINES]
    stroke = max(4, int(main * 0.045))
    boxes = [f.getbbox(text, stroke_width=stroke) for (text, _), f in zip(LINES, fonts)]
    widths = [b[2] - b[0] for b in boxes]
    heights = [b[3] - b[1] for b in boxes]
    total = sum(heights) + GAP * main * (len(LINES) - 1)
    return fonts, stroke, boxes, widths, heights, total

# Largest size whose widest line fits the width and whose stack fits the band.
main = 800
while True:
    fonts, stroke, boxes, widths, heights, total = layout(main)
    if max(widths) <= SIZE - 2 * SIDE and total <= BOTTOM - TOP:
        break
    main -= 5

text_layer = Image.new('RGBA', (SIZE, SIZE), (0, 0, 0, 0))
draw = ImageDraw.Draw(text_layer)
y = TOP + ((BOTTOM - TOP) - total) / 2
for (text, _), font, box, w, h in zip(LINES, fonts, boxes, widths, heights):
    x = (SIZE - w) / 2 - box[0]
    draw.text((x, y - box[1]), text, font=font, fill=FILL, stroke_width=stroke, stroke_fill=STROKE)
    y += h + GAP * main

# A dark glow behind the letters, so they lift off fire and crowd alike.
alpha = text_layer.getchannel('A')
glow = Image.new('RGBA', (SIZE, SIZE), GLOW)
glow.putalpha(alpha.filter(ImageFilter.MaxFilter(15)).filter(ImageFilter.GaussianBlur(28)).point(lambda a: int(a * 0.85)))
out = Image.alpha_composite(glow, text_layer)

# The margins must be empty: crop to content to check where it landed.
bbox = out.getbbox()
assert bbox[1] >= 500 and bbox[3] <= 1500, f"content outside 500..1500: {bbox}"
out.save(sys.argv[1], optimize=True)
print(f"main size {main}px, stroke {stroke}px, content box {bbox}")
