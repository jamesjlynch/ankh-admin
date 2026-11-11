#!/usr/bin/env python3
"""Create review-stage ANKH-branded working copies of exact UK Peptides SDS PDFs.

Only source documents with a matching published ANKH product/strength are included.
Never fabricate chemical sections, CAS numbers, GHS hazards, or certificates.
"""
import json
import re
import hashlib
import urllib.request
from pathlib import Path
from datetime import date
import fitz

ROOT = Path("sds-output")
ROOT.mkdir(exist_ok=True)
SOURCES = {
    "BPC-157 5mg": "bpc-157-5mg-safety-data-sheet.pdf",
    "DSIP 5mg": "dsip-5mg-safety-data-sheet.pdf",
    "Epitalon 10mg": "epitalon-10mg-safety-data-sheet.pdf",
    "GHK-CU 50mg": "ghk-cu-50mg-safety-data-sheet.pdf",
    "Ipamorelin 5mg": "ipamorelin-5mg-safety-data-sheet.pdf",
    "KissPeptin-10 5mg": "kisspeptin-10-peptide-safety-data-sheet.pdf",
    "KPV 10mg": None,  # Resolve exact link from source SDS index
    "MOTS-C 10mg": "mots-c-peptide-safety-data-sheet.pdf",
    "NAD+ 500mg": "nad-plus-500mg-safety-data-sheet.pdf",
    "Selank 5mg": "selank-5mg-safety-data-sheet.pdf",
    "Semax 5mg": "semax-peptide-safety-data-sheet.pdf",
    "SS-31 10mg": "ss-31-10mg-safety-data-sheet.pdf",
    "TB-500 2mg": "thymosin-beta-4-2mg-tb500-safety-data-sheet.pdf",
    "TB-500 5mg": "tb500-peptide-5mg-safety-data-sheet.pdf",
}
NEED_SOURCE = [
    "BPC-157 10mg", "Glow Stack", "Melanotan 1 10mg", "MOTS-C 20mg",
    "Retatrutide 10mg", "Retatrutide 20mg", "Trizepatide 10mg",
]
BASE = "https://www.uk-peptides.com/sds/"
ISSUED = "24 October 2026"
ADDRESS = "167-169 Great Portland Street, London W1W 5PF"
EMAIL = "info@ankhpeptides.com"
PHONE = "07424 524094"
REPLACEMENTS = [
    ("409 Linthorpe Road, Middlesbrough TS5 6JN, United Kingdom", ADDRESS + ", United Kingdom"),
    ("UK Peptides · 409 Linthorpe Road, Middlesbrough TS5 6JN", "ANKH GLOBAL LTD · Company 17289000"),
    ("409 Linthorpe Road, Middlesbrough TS5 6JN", ADDRESS),
    ("https://www.uk-peptides.com", "https://ankhpeptides.com"),
    ("sales@uk-peptides.com", EMAIL),
    ("UK PEPTIDES", "ANKH PEPTIDES"),
    ("UK Peptides", "ANKH GLOBAL LTD"),
    ("Mon–Fri 10:30 – 16:00 GMT/BST", "Contact supplier for availability"),
]
def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent":"Mozilla/5.0 (ANKH SDS rebranding)"})
    with urllib.request.urlopen(req, timeout=30) as res:
        return res.read()
def get_kpv_slug():
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(fetch("https://www.uk-peptides.com/safety-data-sheets/"), "html.parser")
    a = next((a for a in soup.find_all("a", href=True)
              if re.search(r"^KPV\s*10mg$", a.get_text(" ",strip=True), re.I)), None)
    if a is None: raise ValueError("KPV 10mg source link not found")
    n = a.parent
    while n is not None:
        options = [x.get("href", "") for x in n.find_all("a", href=True)
                   if ".pdf" in x.get("href","").lower()]
        if options:
            u = options[0]
            if u.startswith("//"): u = "https:" + u
            elif u.startswith("/"): u = "https://www.uk-peptides.com" + u
            elif not u.startswith("http"): u = "https://www.uk-peptides.com/" + u
            return u
        n=n.parent
    raise ValueError("KPV 10mg PDF link not found")

def draw_cover(page, name, source, old_pages, original_sha):
    w,h=page.rect.width,page.rect.height
    page.draw_rect(fitz.Rect(0,0,w,h),fill=(0.055,0.061,0.063),color=None)
    gold=(0.82,0.66,0.35)
    white=(0.95,0.95,0.92)
    grey=(0.70,0.70,0.67)
    page.draw_rect(fitz.Rect(0,0,w,13),fill=gold,color=None)
    page.insert_text((46,85),"☥",fontsize=35,color=gold,fontname="helv")
    page.insert_text((91,80),"ANKH",fontsize=37,color=white,fontname="hebo")
    page.insert_text((93,103),"P E P T I D E S",fontsize=11,color=gold,fontname="helv")
    page.draw_line((46,128),(w-46,128),color=gold,width=1)
    page.insert_text((46,180),"SAFETY DATA SHEET",fontsize=15,color=gold,fontname="hebo")
    # Long product titles are scaled instead of truncated.
    sz=27 if len(name)<22 else 23
    page.insert_textbox(fitz.Rect(46,202,w-45,288),name,fontsize=sz,color=white,fontname="hebo")
    page.draw_rect(fitz.Rect(46,320,w-46,418),fill=(0.12,0.13,0.13),color=(0.28,0.25,0.19),width=1)
    page.insert_text((60,346),"DRAFT REBRAND | TECHNICAL REVIEW REQUIRED",fontsize=12,color=gold,fontname="hebo")
    lines=[
        "The technical sections following this cover are a rebranded working",
        "copy of the original UK Peptides SDS for the same named product.",
        "Not approved for customer publication until competent SDS review."
    ]
    for j,line in enumerate(lines):page.insert_text((60,370+j*15),line,fontsize=10,color=white)
    fields=[
        ("Responsible UK supplier","ANKH GLOBAL LTD | Company No. 17289000"),
        ("Registered office",ADDRESS),
        ("Supplier contact",EMAIL+" | "+PHONE),
        ("Website","https://ankhpeptides.com"),
        ("Rebrand preparation date",ISSUED),
        ("Source PDF SHA-256",original_sha[:24]+"..."),
        ("Source length",str(old_pages)+" pages, retained behind this cover"),
    ]
    y=457
    for label,value in fields:
        page.insert_text((46,y),label.upper(),fontsize=8.5,color=gold,fontname="hebo")
        page.insert_textbox(fitz.Rect(46,y+7,w-46,y+31),value,fontsize=10,color=white)
        y+=36
    page.insert_textbox(fitz.Rect(46,h-78,w-46,h-35),
        "This cover is document control, not a new independent hazard assessment. "
        "Do not use as a final SDS until identity, composition, physical form, "
        "hazards and emergency-contact arrangements are verified.",
        fontsize=9,color=grey)
    return page

def patch_page(page):
    # Replace only text identifying the previous supplier; preserve the source
    # chemical, handling, hazard and transport sections unchanged.
    replacements=[]
    occupied=[]
    for old,new in REPLACEMENTS:
        for rect in page.search_for(old):
            if any(rect.intersects(p) and (rect & p).get_area() > min(rect.get_area(),p.get_area())*.15
                   for p in occupied): continue
            occupied.append(rect)
            replacements.append((rect,new))
            # White redaction only affects original text, not figures/images.
            page.add_redact_annot(rect+(-.7,-.3,.7,.3),fill=(1,1,1))
    if replacements:
        page.apply_redactions(images=0, graphics=0, text=0)
        for rect,value in replacements:
            # Exact source lines are sometimes columns: avoid spill into adjacent cells.
            room=min(page.rect.width-rect.x0-35, max(rect.width+95,170))
            if rect.y0 < 115 and value=="ANKH PEPTIDES":
                font="hebo"; size=15; color=(0.52,0.35,0.09)
            else:
                font="helv"; size=8.3 if len(value)>45 else 9; color=(0.10,0.10,0.10)
            target=fitz.Rect(rect.x0, rect.y0-.5, rect.x0+room, rect.y0+max(11,rect.height+3))
            page.insert_textbox(target,value,fontsize=size,fontname=font,color=color)
    # Reinstate branded page furniture above/below the original SDS content.
    w,h=page.rect.width,page.rect.height
    gold=(0.80,0.63,0.31)
    page.draw_rect(fitz.Rect(0,0,w,5),color=None,fill=gold)
    # A complete clean footer prevents old provider names/addresses remaining there.
    page.draw_rect(fitz.Rect(0,h-44,w,h),color=None,fill=(1,1,1))
    page.draw_line((42,h-36),(w-42,h-36),color=gold,width=1)
    page.insert_text((42,h-21),"ANKH PEPTIDES  |  ANKH GLOBAL LTD  |  No. 17289000",fontsize=8,color=(0.25,0.25,0.25))
    return page

def process(name,slug):
    if slug is None:
        url=get_kpv_slug()
    else:
        url=BASE+slug
    src=fetch(url)
    if not src.startswith(b"%PDF"): raise ValueError("Not a PDF: "+url)
    original_sha=hashlib.sha256(src).hexdigest()
    doc=fitz.open(stream=src,filetype="pdf")
    oldpages=doc.page_count
    if oldpages<4:raise ValueError("Unexpectedly short SDS")
    # Confirm it is the intended product, not an unrelated PDF.
    top=doc[0].get_text()
    norm=lambda x:re.sub(r"[^a-z0-9]","",x.lower())
    canonical = {"TB-500 2mg":"Thymosin Beta 4", "TB-500 5mg":"Thymosin Beta 4",
                 "KissPeptin-10 5mg":"KISSPEPTIN 10","GHK-CU 50mg":"GHK-Cu",
                 "NAD+ 500mg":"NAD","MOTS-C 10mg":"MOTS-C"}.get(name,name)
    if norm(canonical.split(" 5mg")[0].split(" 10mg")[0].split(" 50mg")[0]) not in norm(top):
        raise ValueError("Source identity doesn't match "+name)
    for page in doc:patch_page(page)
    cover=fitz.open()
    page=cover.new_page(width=595,height=842)
    draw_cover(page,name,url,oldpages,original_sha)
    cover.insert_pdf(doc)
    cover.set_metadata({"title":"ANKH Peptides SDS rebrand draft — "+name,
        "author":"ANKH GLOBAL LTD","subject":"Supplier identity rebrand; technical review required"})
    out=ROOT/("ANKH-SDS-"+re.sub(r"[^A-Za-z0-9]+","-",name).strip("-")+".pdf")
    cover.save(out,garbage=4,deflate=True)
    check=fitz.open(out)
    extract="\n".join(page.get_text() for page in check)
    if "UK PEPTIDES" in extract or "409 Linthorpe Road" in extract or "sales@uk-peptides.com" in extract:
        raise ValueError("Some previous supplier details remain: "+name)
    return {"product":name,"status":"DRAFT - TECHNICAL REVIEW REQUIRED",
      "source":url,"source_sha256":original_sha,"output":out.name,
      "source_pages":oldpages,"output_pages":check.page_count,"bytes":out.stat().st_size}

def main():
    results=[]
    for name,slug in SOURCES.items():
        try:
            row=process(name,slug)
        except Exception as exc:
            row={"product":name,"status":"FAILED","error":str(exc)}
        print("SDS_RESULT "+json.dumps(row,ensure_ascii=False),flush=True)
        results.append(row)
    for name in NEED_SOURCE:
        results.append({"product":name,"status":"NO EXACT UK PEPTIDES SOURCE SDS"})
    (ROOT/"MANIFEST.json").write_text(json.dumps(results,indent=2,ensure_ascii=False))
    (ROOT/"README.txt").write_text(
        "ANKH PEPTIDES - SDS REBRANDING WORKING COPIES\n"
        "Company: ANKH GLOBAL LTD | 17289000\n"
        "Preparation date: 24 October 2026\n\n"
        "These PDFs are DRAFT working copies only; technical review and final\n"
        "publication approval are required before providing as Safety Data Sheets.\n"
        "Underlying chemical, hazard, first aid, handling, toxicological and\n"
        "transport content derives from the linked UK Peptides source PDF.\n"
        "No source data was inferred for products lacking a source SDS.\n"
        "See MANIFEST.json for source URLs, checksums and exceptions.\n"
    )
    completed=sum(x["status"].startswith("DRAFT") for x in results)
    print("COMPLETED_DRAFTS",completed,"OF",len(SOURCES))
    if completed<10: raise SystemExit("Source retrieval and conversion under threshold")
if __name__=="__main__":main()
