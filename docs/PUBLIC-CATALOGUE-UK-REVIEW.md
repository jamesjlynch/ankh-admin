# ANKH public scientific catalogue — UK pre-release checklist

**Status:** public reference page implemented with a default-deny per-product listing gate. **Not legal clearance.** This checklist is for ANKH's retained evidence file and its independent MHRA/medicines-regulatory adviser. Update it for current law and the exact GB/NI destinations before advertising or supply.

## 1. Classification before advertising or offering supply

- [ ] Record the exact molecule, salt form, formulation, vial packaging, proposed customers, proposed use, labels, photographs, storefront presentation, and payment/contact flow for each product.
- [ ] Obtain an appropriate assessment of whether the item meets the UK definition of a medicine by **presentation or function**. A disclaimer such as "research use only" or "not for human consumption" cannot, by itself, disapply medicines law.
- [ ] Determine if a marketing authorisation, manufacturer's/importer's/wholesaler's authorisation or other exemptions are necessary. Verify legal supply separately from advertising permissions.
- [ ] Do not advertise prescription-only medicines or unlicensed medicinal products to the general public. Do not promote unlicensed medicine-related treatment, prevention, injury, weight loss, tanning, anti-ageing, hormone, sports recovery, disease or medical effects.
- [ ] For potentially medicinal compounds (especially products named like prescription medicines or active pharmaceutical ingredients), obtain advice **before** any public product-specific promotional page. A retail sales page on a commercial site can be an advertisement even if it shows scientific references and says "for research only."
- [ ] Treat the claimed effect of a medicine and any implicit promise conveyed by scientific images, links, product cards, categories, testimonials and WhatsApp marketing as advertising content requiring review.
- [ ] Record country/territory (England/Wales/Scotland vs Northern Ireland) and any applicable divergent classification.

**Primary references**
- MHRA, medicine classification: https://www.gov.uk/guidance/borderline-products-how-to-tell-if-your-product-is-a-medicine
- MHRA, public advertising and factual catalogues: https://www.gov.uk/guidance/advertise-your-medicines
- Human Medicines Regulations 2012, regulation 46 (offering unauthorised medicines): https://www.legislation.gov.uk/uksi/2012/1916/regulation/46
- CAP Code section 12: https://www.asa.org.uk/type/non_broadcast/code_section/12.html
- ASA 2026 report addressing research peptide advertising: https://www.asa.org.uk/resource/pulse-report-health-claims-in-online-ads.html

## 2. Product identity and documentation

- [ ] Manufacturer/supplier and actual lot/batch number recorded privately.
- [ ] Reference substance identity, exact sequence, salt/counterion, modifications and blend composition **verified**, not inferred solely from the product nickname (especially TB-500, CJC-1295 variants, PEG-MGF and named blends).
- [ ] Only show numerical purity, analytical methods, mass, and assay results supported by genuine batch documents. HPLC chromatogram alone is not proof of sterility or of the correct sequence.
- [ ] Confirm source, independence, issue date, sample identity and the exact lot connection of any Certificate of Analysis before posting it.
- [ ] Assess applicable UK chemical classification, hazard labelling, package specifications and Safety Data Sheet obligations (not every item is regulated identically; professional advice required).
- [ ] Record actual supplier stability information for *this chemical form and packaging*. Do not generate universal -20°C or 2–6°C shelf-life guarantees or instructions for human reconstitution.
- [ ] Have a supplier/traceability and recall process for any goods lawfully supplied.

**References**
- HSE UK/GB CLP: https://www.hse.gov.uk/chemical-classification/brexit.htm
- HSE Safety Data Sheets: https://www.hse.gov.uk/chemical-classification/labelling-packaging/safety-data-sheets.htm
- HSE UK REACH: https://www.hse.gov.uk/chemicals/manufacture-supply.htm

## 3. Claim-by-claim evidence review

- [ ] Every product page separates **chemical identity** from **published experiments** from **claims about the actual supplied lot**.
- [ ] Attach the actual paper title, PMID/DOI, publication year, research population/model (human, animal, culture), and main limitations to each paper presented.
- [ ] Do not imply that an animal experiment proves a health outcome for customers. Do not transfer results from a regulated clinical preparation to a research vial.
- [ ] Remove ambiguous or incorrect PubMed reference links and generic searches where possible, replacing them with directly relevant primary studies or labelled background sources.
- [ ] Keep a dated copy of the final reviewed webpage text/screenshots and identity of the reviewer.
- [ ] Check all product imagery for implied injection, human dosing, body transformation, weight-loss, treatment, or endorsements.
- [ ] Do not add testimonials, before/after images, drug-dosing instructions, injection guidance or medical suitability claims to a public research catalogue.

## 4. Business and website disclosure

- [ ] Verify and display ANKH GLOBAL LTD's exact Companies House registration number, official registered office address, jurisdiction and full company name on the public website. **Do not copy an unverified similar-company listing.**
- [ ] Display genuine public contact details, and an appropriately scoped privacy notice if personal data such as website logs is processed. Configure cookie consent if non-essential cookies or trackers are added.
- [ ] Before offering *lawful* online sales, have terms, actual delivery information, correct VAT information as applicable, cancellation/refund rules, pricing/delivery costs and secure checkout wording reviewed.
- [ ] Ensure the checkout, account pages, WhatsApp links, emails and other linked sites do not undermine the claimed research-only intended market.

**References**
- Companies Act trading disclosures: https://www.gov.uk/running-a-limited-company/signs-stationery-and-promotional-material
- Online and distance selling: https://www.gov.uk/online-and-distance-selling-for-businesses/online-selling

## 5. Current application controls

- Independent review record `catalogue_public_reviews` with default public visibility **off** for every product.
- Authenticated review form in **Admin → Products → Product → Independent public listing review**.
- Requires recorded reviewer, substantive legal/advertising assessment and three explicit attestations before releasing a technical profile.
- Later editing any catalogue description/image/structured information automatically revokes public release. The review must be repeated on revised content.
- Public endpoint `/ankh-admin/public-catalogue.php` does not need a customer account and lists **only** individually reviewed active products. It displays no retail prices, family prices, stock counts, supplier costs, orders, customer names, checkout or purchase prompts.
- Public page shows prominent non-medical-use notice, research evidence limitations and noindex/nofollow while prelaunch review is outstanding.
- Internal account catalogue visibility remains separate and unchanged.
- **The gate is a workflow safeguard, not an MHRA legal determination; do not tick attestations without real documented evidence.**

## Release decision

**No public peptide listings should be released simply because this checklist exists.** Obtain review from a UK solicitor or regulatory adviser experienced in MHRA classification, medicinal advertising and chemical supply **for the actual product list and intended commercial model**. A company website may still count as advertising even when it only displays a scientific summary.

Document name/date of sign-off: ___________________________

Scope and territories: ____________________________________

Supporting advice reference / archived file: ________________

Approved product identifiers: _____________________________
