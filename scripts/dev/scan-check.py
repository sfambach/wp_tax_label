#!/usr/bin/env python3
"""Rasterises the scan sheet as a label printer would (black and white, its dpi) and reads every code back.

Needs: pdftoppm (poppler-utils), pip install zxing-cpp pillow.
Usage: python3 -I scripts/dev/scan-check.py [dpi ...]   (default 203 300)
"""
import subprocess, sys, tempfile, os
import zxingcpp
from PIL import Image

root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
want = {'QRCode', 'DataMatrix', 'Code128', 'Code39', 'EAN13'}
failed = False
for dpi in sys.argv[1:] or ['203', '300']:
    with tempfile.TemporaryDirectory() as tmp:
        pdf = os.path.join(tmp, 'sheet.pdf')
        subprocess.run(['php', os.path.join(root, 'scripts/dev/scan-sheet.php'), pdf, dpi], check=True)
        # Drawn for the default 203 dpi it must also scan from a finer printer; drawn for a finer one, only from that one.
        for raster in [dpi, '300'] if dpi == '203' else [dpi]:
            subprocess.run(['pdftoppm', '-r', raster, '-mono', '-png', pdf, os.path.join(tmp, 'p' + raster)], check=True)
            png = os.path.join(tmp, 'p' + raster + '-1.png')
            got = {r.format.name for r in zxingcpp.read_barcodes(Image.open(png))}
            missing = want - got
            print(f'drawn for {dpi} dpi, printed at {raster} dpi:', 'ok' if not missing else 'MISSING ' + ', '.join(sorted(missing)))
            failed = failed or bool(missing)
sys.exit(1 if failed else 0)
