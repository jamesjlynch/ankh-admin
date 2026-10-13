# ANKH Admin — Mobile UX overhaul

A staged, mobile-first presentation upgrade for the existing PHP/SQLite application. UX-only changes must not rewrite customers, orders, product prices, fridge stock, stock reservations, supplier prices or financial history.

## Page review — 13 October 2026

| Screen | Existing mobile capabilities | Next opportunity |
| --- | --- | --- |
| Dashboard | Sales summaries, attention lists, one-tap quick actions | Prioritise items requiring action |
| Orders | Search, scope chips, expandable orders and preparation states | Review delivery actions and editing flow |
| New/Edit Order | Multi-item product picker and sticky total | Improve long form progress and keyboard overlap |
| Customers & Balances | Search, filters, expandable customer history | Keep common actions easier to reach |
| Products | Searchable tiles, product and stock editors | Reduce repeated stock-control options |
| Stock Overview | Combined fridge totals, Add/Move actions and four tabs | Surface shortages and incoming counts clearly |
| Stock Incoming | Product-level quantities and long expanded supplier orders | **Stage 2: concise expandable order and product summaries** |
| Stock Forecast/Reorder | Demand and recurring-order calculations | Clarify links to supplier basket |
| Supplier & Profit | Matched/ranged products, smart buying and supplier basket | Coordinate with separate supplier UX PR; avoid overlapping changes |
| Reports | Horizontal tabs and compact filter disclosure | Improve charts, reporting presets and comparison |
| More/Settings | Two-up mobile utility tiles | Group rare functions below daily tasks |

## Stage 1 — already merged to main

Dashboard quick actions; faster Orders and Customers filters with visible result counts; mobile-first report options and horizontal navigation; More menu two-up tiles; reduced-motion support.

## Stage 2 — implemented in this branch

- Incoming quantities by product move into a disclosure with the total still visible.
- Each pending supplier order becomes one concise tappable summary showing its status, supplier, date and units outstanding.
- Expanding the supplier order exposes the unchanged product quantities and original Receive/Allocate forms.
- Better iPhone touch targets for receipt controls, visible keyboard focus and reduced motion.
- Global Skip to main content link for keyboard and switch navigation.

Acceptance: lint and existing order/stock/supplier tests pass; new static Incoming Stock regressions pass. No database schemas, writes, stock maths, financial calculations or server POST handlers changed.

## Stage 3 — planned, not implemented

- Check iOS safe area, portrait/landscape and keyboard overlap with sticky order and supplier basket totals.
- Improve the New/Edit Order progressive flow and confirmation feedback.
- Review accessibility and whitespace of stock/product editors, customer history and reporting details.
- Device QA at 320, 375, 390 and 430px, including VoiceOver, long product names and no-JavaScript fallbacks.

## Safe deployment

Keep each PR independently testable and reversible. Never run setup.php or overwrite live SQLite for mobile design changes. GitHub merging is not proof the Krystal site has updated, and database backup remains a separate hosting responsibility.
