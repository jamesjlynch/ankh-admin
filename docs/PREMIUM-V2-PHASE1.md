# ANKH Premium V2 — Phase 1 implementation and rollout

**Status:** UI foundation created in an isolated feature branch. **Do not merge/publish to production** until reviewed on authenticated iPhone and desktop screens.

**User goal:** A premium, modern, native-feeling black-and-gold business-management app, drawing on the dark, editorial, image-led mobile references previously discussed. Keep ANKH recognisable, avoid a generic SaaS dashboard, and do not rebuild or reset existing business logic.

## What already exists

The existing ANKH Admin is a substantial PHP + SQLite app with a shared desktop sidebar, five-tab mobile navigation, notifications, customer profiles, order preparation/delivery, two fridge views and combined stock, product catalogue photographs, reports and supplier purchasing. A prior enhancement loads uploaded photos from the existing authenticated `catalogue-image` endpoint. There are multiple generations of CSS rules; rewriting `style.css` all at once is unnecessarily risky.

## Phase 1A: visual foundation (this branch)

- New **`premium-v2.css`** contains scoped design tokens and new rules for panels, orders, product tiles, inventory, dashboard summaries, typography, forms, filter strips and navigation.
- Existing product-photo code and endpoints are reused. No new uploads or duplicate image storage.
- Opt-in **authenticated preview**: visit `?view=dashboard&premium=1`; the design persists across existing links for that browser session. A clearly labelled preview strip links to `?view=dashboard&premium=0` to restore the original design.
- The class `body.ankh-premium-v2` scopes all overrides. The original interface remains the default.
- New asset is included in the atomic Krystal publishing manifest and deployment integrity checks, **but not actually deployed until the PR is merged**.
- No changes to any stock calculations, product data, order processing, pricing, payments, database structure, shipping, or customer-facing portal.
- No extra runtime JS dependency. Existing navigation remains ordinary links and original forms remain functional without added scripts.

## Visual specifications

| Element | Treatment |
| --- | --- |
| Canvas | Rich near-black (#0b0d0d) with very subtle bronze ambient gradient |
| Surface | Warm charcoal (#171a19), raised cards (#202321), subtle layered depth |
| Accent | Muted luxury gold (#e2be72), brighter highlight (#f0d79b) |
| Primary text | Warm off-white (#f6f3e9), supporting text soft grey-green (#a9afa8) |
| Radii | 19px feature cards, 13px inputs/buttons, pill status/filter chips |
| Type | Native iPhone font fallback followed by existing sans-serif; numeric and headline hierarchy |
| Controls | Minimum 44–48px practical tap area; 16px text on form fields to stop Safari zoom |
| Movement | Subtle hover feedback; `prefers-reduced-motion` disables ornamentation |
| Images | Product photographs retain their real aspect ratios, with a consistent neutral vignette and fallback vial icon |
| Navigation | Soft floating five-tab bar on phones, distinctive gold New action, tidy desktop left rail |

Avoid bright yellow, gold-on-gold fields, gratuitous animation, text truncated without any accessible alternative, and layout dependent on horizontal scrolling.

## Screen-by-screen plan

### 1. Global shell (first)

**Desktop:** consistent left sidebar, active state, brand, notification button, page max-width and side padding. Don't remove hidden/secondary admin destinations.

**iPhone:** refine existing mobile brand row, notification access, bottom five tabs (Dashboard / Orders / New / Customers / More), page gutters and safe-area spacing. Preserve the selected state for subviews through More. Keep Sign out accessible.

**Pass when:** pages do not shift on scroll; notifications open above the navigation; keyboard focus remains visible; at 320px and 390px all five tabs remain readable; interactive controls are reachable without hidden overlays.

### 2. Dashboard (Phase 1 foundation, Phase 2 refinement)

- Preserve the six existing cards: Today's Sales, This Month's Sales, Gross Profit, Unpaid, To Deliver, Low Stock.
- Make cards visually consistent: label, large number, supportive descriptor.
- Keep **To Deliver** count correct for future and delivered-but-unpaid states.
- Maintain quick links to filtered orders, stock, reports and supplier actions.
- Keep the action queue as the key content below summaries — not giant decorative graphs.
- Future refinement: photo-led priority orders and restocking alerts as a compact horizontal ribbon.

**Pass when:** every summary routes to the existing correct filtered screen; quick tasks stay visible on iPhone without excessive scrolling.

### 3. Products (Phase 1 foundation, Phase 2 dedicated page)

- Preserve image-led grid/list view and uploaded product-photo display.
- Large consistent image panel, strength, name, price and available/low badge.
- Preserve the ability to expand a product and edit fields.
- Next work: dedicated product detail page with retail/Family & Friends/cost, Jay/Tony/combined/physical/reserved stock, incoming supplier units, recent orders and reorder status.

**Pass when:** product photographs never crop labels; missing images fall back gracefully; existing save/stock/edit forms still work.

### 4. Stock (Phase 1 foundation, Phase 4 intelligence)

- Keep Combined as the starting stock view, with Jay and Tony views easily accessible.
- Refine photo card hierarchy, the low-stock toggle, reservations, incoming quantities and quick links to source orders.
- Don't change reserved-stock calculations or movement-history data.
- Next work: detailed per-product stock drawer/page and reorder justification.

**Pass when:** Combined totals always match Jay + Tony; low-stock filters and page controls are tappable and do not affect stock.

### 5. Orders (Phase 1 foundation, Phase 3 operations)

- Improve cards, filter chips, search, preparation badges and row spacing.
- Preserve **payment status as independent** of preparation and delivery.
- Next work: visual packing station with product image, quantity, format and pick checklists, then dedicated workflow views.
- Packing checks must **never silently mark an order delivered or paid**.

**Pass when:** all existing actions (prepare, packaged, deliver, record payment, edit, future delivery) still work, including £0.00 internal orders.

### 6. New order

- Reduce visual clutter in customer, product, quantity and fulfilment steps.
- Reuse photographs in the product chooser, but preserve labels and selectable strengths.
- Keep amount/format/cost confirmation explicit.
- No hidden automatic order creation triggered by visual interactions.

**Pass when:** order entry remains operable on 320px iPhone SE-sized layouts, and switching between steps cannot lose unsaved lines.

### 7. Customers

- Clean profile summary and searchable customers list.
- Keep existing spend/profit, last order, recurring and outstanding balance metrics.
- Next work: photo-led purchase history and separate recurring timeline, with overdue payments prominent.

**Pass when:** tapping a customer opens the same record, and no account fields disappear behind overflow.

### 8. Reports, Supplier, Business Supplies and More

- Apply tokens and spacing consistently without redesigning dense financial tables blindly.
- Preserve all report filters/exports and supplier calculator inputs.
- Make More tiles legible and keep every existing feature reachable.
- Future redesign should use progressive disclosure, not conceal important totals.

**Pass when:** horizontal tables scroll inside their own containers; export buttons stay usable; changes to supplier quantities require existing confirmations.

## Phase 2A: Products V2 — implemented on the same development branch

- **Photo-led active-products catalogue** replaces the visual grid *only while an authenticated admin opts into Premium V2*. Each photograph is retrieved from the existing catalogue image endpoint; missing photos use an ANKH fallback. Existing products retain their current management forms.
- **Dedicated product workspace** at \`?view=product&id=ID\`, rendering the product image, retail and Family & Friends prices, cost, indicative margin, Jay/Tony/unallocated quantities, reserved/physical totals, incoming supplier units, low-stock threshold, 30-day order quantities, latest 12 order lines and latest 8 stock movements.
- **Safe edit handoff**: “Edit product settings” links to \`?view=products&edit_product=ID\`; the original product editor (including photo uploads and physical stock count confirmations) opens on that specific product and remains unchanged.
- **Order history deep links** prefill the existing Orders search field, opening the corresponding record list without affecting statuses.
- **No database schema or business-logic edits.** Prices and stock figures are read from current tables. The product workspace makes no POSTs.
- **Regression test** \`tests/product-v2.php\` uses isolated SQLite data to confirm counts, pricing, photo links and customer-order activity; the atomic publisher verifies both new product assets.

**Important:** this stage is a new UI and read-only analytics surface. The existing “create order” button leads to the normal new-order flow without automatically selecting a product. Larger product photo uploads are still served through the existing secure image endpoint; purpose-built thumbnail resizing remains a later performance improvement. The entire stage still requires real iPhone visual review.

## Next build slices after Phase 1A

**Phase 1B — device QA and refinement:** collect actual iPhone screenshots at 320 / 375 / 390 / 430 CSS pixels and desktop at 1024 / 1440. Fix wrapping, sticky overlays, alerts, focus, and any conflicting accumulated CSS. Approve consistent design before making it default.

**Phase 2 — Products V2:** dedicated overview and photo-led catalogue now implemented in this branch; next: visual QA, thumbnail optimisation and deeper integrated supplier insights. Avoid duplicating editing fields.

**Phase 3 — Order/Packing V2:** visual packing station and readable order journey. Define audited picking workflow that never changes delivery/payment without explicit action.

**Phase 4 — Stock V2:** stock overview with location breakdown, physical/available/reserved counts, incoming, demand forecasts and actionable reorder recommendations.

**Phase 5 — Customers V2:** order photo history, active recurring cycles, outstanding balances and customer insights with existing audit and privacy boundaries.

## Go-live gate

1. PHP lint and isolated publisher assets regression must pass.
2. Test on real authenticated iPhone Safari and Chrome/desktop using non-production fixture data; no assumptions from CSS alone.
3. Validate selected navigation, order creation/edit/preparation/delivery, zero-cost internal orders, stock reservation release, Jay/Tony totals, business supplies, reports, notifications, logout.
4. Confirm photos load through the authenticated endpoint and default fallback shows for unavailable pictures.
5. Compare screenshot layout against the agreed luxury dark/gold reference.
6. Confirm private SQLite backup/integrity; publishing script only touches source files.
7. Publish during a low-traffic window after approval; monitor real error logs and have a rollback commit ready.

## Known limitations at this point

- **Not a finished redesign of every screen.** Phase 1A lays down a coherent opt-in styling foundation.
- No hosted staging URL is provided by this feature branch. GitHub branches do not automatically create a Krystal preview site.
- The opt-in preview link only works **after** these changes are deployed to a test or approved environment.
- Visual QA on real devices has not yet been completed.
- Existing unrelated monolithic CI tests have had failures on the main branch and are not treated as successful merely because the isolated CSS/deployer checks pass.
