"""Recompress photographic PDF images without rebuilding the PDF structure.

Requires pikepdf and Pillow. Always inspect the resulting PDF before publishing:
python3 scripts/optimize-catalog-pdf.py input.pdf output.pdf
"""

import io
import sys

from PIL import Image
from pikepdf import Name, Pdf, PdfImage


def main(source: str, destination: str) -> None:
    with Pdf.open(source) as pdf:
        processed = set()
        for page in pdf.pages:
            for raw_image in page.get_images().values():
                identity = raw_image.objgen
                if identity in processed or raw_image.get("/Filter") != Name.FlateDecode:
                    continue
                processed.add(identity)
                if int(raw_image.get("/Width", 0)) < 1000:
                    continue
                image = PdfImage(raw_image).as_pil_image().convert("RGB")
                output = io.BytesIO()
                image.save(output, format="JPEG", quality=85, optimize=True, subsampling=0)
                if len(output.getvalue()) >= len(raw_image.read_raw_bytes()):
                    continue
                raw_image.write(output.getvalue(), filter=Name.DCTDecode)
        pdf.save(destination, compress_streams=True, object_stream_mode=1)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: optimize-catalog-pdf.py input.pdf output.pdf")
    main(sys.argv[1], sys.argv[2])
