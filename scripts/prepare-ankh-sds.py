#!/usr/bin/env python3
"""Prepare ANKH technical-review SDS drafts from the existing ANKH 14-file CI pack.
Remove the internal cover, recolour legacy blue design to ANKH gold, retain the
source technical pages. Output drafts; NEVER claim an independent safety review.
"""
from pathlib import Path
import json
import re
import fitz
import numpy as np
from PIL import Image

root = Path('sds-source')
destination = Path('sds-ready')
destination.mkdir(exist_ok=True)
names = [
    'BPC-157-5mg','DSIP-5mg','Epitalon-10mg','GHK-CU-50mg',
    'Ipamorelin-5mg','KissPeptin-10-5mg','KPV-10mg','MOTS-C-10mg',
    'NAD-500mg','Selank-5mg','Semax-5mg','SS-31-10mg','TB-500-2mg','TB-500-5mg'
]
gold = (199, 161, 84)
for name in names:
    path = root / f'ANKH-SDS-{name}.pdf'
    if not path.is_file():
        raise RuntimeError('Expected source PDF missing: '+str(path))
    src = fitz.open(path)
    if src.page_count < 7:
        raise RuntimeError('Source appears incomplete: '+name)
    # Existing workflow's page zero is a private design/review cover.
    out = fitz.open()
    for idx in range(1, src.page_count):
        page = src[idx]
        pm = page.get_pixmap(matrix=fitz.Matrix(1.5,1.5), alpha=False)
        arr = np.array(Image.frombytes('RGB',(pm.width,pm.height),pm.samples))
        r=arr[:,:,0].astype('int16')
        g=arr[:,:,1].astype('int16')
        b=arr[:,:,2].astype('int16')
        old_blue=(b>80)&(b>r+25)&(b>g+10)
        arr[old_blue]=gold
        tmp=fitz.open()
        image=Image.fromarray(arr)
        import io
        stream=io.BytesIO()
        image.save(stream,format='PNG', optimize=True)
        new=out.new_page(width=page.rect.width,height=page.rect.height)
        new.insert_image(new.rect,stream=stream.getvalue())
        h=new.rect.height; w=new.rect.width
        new.draw_rect(fitz.Rect(0,h-46,w,h),fill=(1,1,1),color=None)
        new.draw_line((40,h-35),(w-40,h-35),color=(0.78,0.63,0.33))
        new.insert_text((40,h-20),'ANKH PEPTIDES  |  ANKH GLOBAL LTD  |  COMPANY NO. 17289000',
                        fontsize=8,color=(0.24,0.24,0.24))
        new.insert_text((w-181,h-9),'DRAFT - TECHNICAL REVIEW',fontsize=7,color=(0.5,0.25,0.15))
    if out.page_count < 6:raise RuntimeError('Unexpected output page count')
    out.set_metadata(dict(title='ANKH SDS draft — '+name,author='ANKH GLOBAL LTD',
                          subject='Internal technical-review working copy; not authorised for customer publication'))
    target=destination / f'ANKH-SDS-{name}.pdf'
    out.save(target,garbage=4,deflate=True)
    print('CREATED',target.name,target.stat().st_size,'pages',out.page_count,flush=True)
if len(list(destination.glob('ANKH-SDS-*.pdf')))!=14:raise RuntimeError('Incomplete batch')
print('ALL 14 ANKH SDS DRAFTS PREPARED - NO INTERNAL COVERS')
