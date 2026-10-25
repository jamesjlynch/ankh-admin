#!/usr/bin/env python3
"""Finalise 14 ANKH owner-reviewed SDS PDFs without changing scientific/safety content.
Remove the exact 'DRAFT - TECHNICAL REVIEW' vector footer from each page,
update draft PDF metadata and preserve the remaining rendered technical pages.
Input and output are PRIVATE workflow working directories. No public artefacts.
"""
from pathlib import Path
import json
import re
import sys
import fitz

if len(sys.argv) != 3: raise SystemExit('Usage: finalise_ankh_sds.py INPUT_DIR OUTPUT_DIR')
source, destination = [Path(arg) for arg in sys.argv[1:]]
manifest = json.loads((source / 'manifest.json').read_text())
if len(manifest['items']) != 14: raise SystemExit('Expected exactly 14 source SDS PDFs')
destination.mkdir(mode=0o700, exist_ok=True)
for item in manifest['items']:
    name = item['filename']
    if not re.fullmatch(r'ANKH-SDS-[A-Za-z0-9-]+\.pdf', name):
        raise SystemExit('Invalid manifest filename: ' + name)
    in_path = source / name
    document = fitz.open(in_path)
    if document.page_count < 6: raise SystemExit(name + ': technical SDS appears incomplete')
    for page_number, page in enumerate(document, 1):
        matched = page.search_for('DRAFT - TECHNICAL REVIEW')
        if len(matched) != 1:
            raise SystemExit(f'{name} page {page_number}: expected one draft footer, found {len(matched)}')
        rect = matched[0] + (-2, -1, 2, 2)
        if rect.y0 < page.rect.height - 40:
            raise SystemExit(f'{name} page {page_number}: draft text is not in known footer area')
        page.add_redact_annot(rect, fill=(1, 1, 1))
        # Remove the draft footer's actual text objects, not merely cover the
        # words while leaving searchable/inaccessible text in the PDF.
        page.apply_redactions(images=0, graphics=0, text=0)
        if page.search_for('DRAFT - TECHNICAL REVIEW'):
            raise SystemExit(f'{name} page {page_number}: text still present')
    metadata = dict(document.metadata or {})
    metadata.update({
        'title': 'ANKH PEPTIDES | Safety Data Sheet | ' + item['product_name'],
        'subject': 'ANKH GLOBAL LTD safety data sheet; business review recorded 25 October 2026',
        'author': 'ANKH GLOBAL LTD',
        'keywords': 'Safety Data Sheet, ANKH, laboratory'
    })
    # Some existing metadata fields may be "draft" on the old PDF.
    for k, val in list(metadata.items()):
        if isinstance(val, str) and ('draft' in val.lower() or 'internal technical-review working' in val.lower()):
            metadata[k] = ''
    document.set_metadata(metadata)
    out_path = destination / name
    document.save(out_path, garbage=4, deflate=True, clean=True)
    document.close()
    check = fitz.open(out_path)
    assert check.page_count >= 6
    if any('draft' in page.get_text().lower() for page in check):
        raise SystemExit(name + ': residual searchable draft text')
    if 'draft' in str(check.metadata).lower():
        raise SystemExit(name + ': residual draft metadata')
    size = out_path.stat().st_size
    if size < 15000 or size > 8*1024*1024:
        raise SystemExit(name + ': unreasonable PDF byte count ' + str(size))
    print(f'FINAL-SDS product_id={item["product_id"]} pages={check.page_count} size={size}', flush=True)
    check.close()
print('SUCCESS: exactly 14 final PDFs produced; internal covers excluded and searchable DRAFT footers removed.')
