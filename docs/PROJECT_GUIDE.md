# MediStock Intelligence — Complete Project Guide

This guide covers the whole system: what it is for, who uses it, how each module works, how the intelligence and AI features reach their answers, how to install and run it, and how the code is organised.

It is written for three audiences, and you can skip to your part:

| You are… | Read |
|---|---|
| A hospital user (storekeeper, pharmacist, manager…) | Parts 1–4 |
| The system administrator | Parts 1, 2, 5, 7, 8 |
| A developer maintaining the code | Everything, especially Parts 6–9 |

---

## Contents

1. [What MediStock is](#part-1--what-medistock-is)
2. [Users, roles and permissions](#part-2--users-roles-and-permissions)
3. [Core ideas you need to understand](#part-3--core-ideas)
4. [Module-by-module user guide](#part-4--module-by-module-user-guide)
5. [Administration](#part-5--administration)
6. [Intelligence and AI — how the answers are calculated](#part-6--intelligence-and-ai)
7. [Installation, deployment and daily operation](#part-7--installation-deployment-and-operation)
8. [Security](#part-8--security)
9. [Developer guide](#part-9--developer-guide)
10. [Troubleshooting and FAQ](#part-10--troubleshooting-and-faq)
11. [Glossary](#part-11--glossary)

---

# Part 1 — What MediStock is

MediStock Intelligence is a web-based inventory and resource management system for a hospital. It tracks every medicine, consumable and reagent from the moment it arrives from a supplier until it is used on a patient, expires, is damaged or is returned. On top of that record it predicts shortages, warns about expiry, suggests moving stock between departments and spots unusual activity.

## 1.1 Problems it solves

| Hospital problem | How MediStock handles it |
|---|---|
| Medicines run out without warning | Stock-out forecasting from real consumption, reorder points and alerts |
| Stock expires on shelves | First-Expiry-First-Out (FEFO) issuing, an expiry risk simulation and redistribution suggestions |
| Nobody knows what each ward holds | Per-department stock, levels and a complete movement ledger |
| Losses and theft go unnoticed | An append-only ledger, approvals, blind counts, anomaly detection and an audit log |
| Paper requests and purchase orders get lost | Digital requests, approvals, purchase orders (PDF) and goods receipts |
| Recalls are slow and incomplete | Batch tracking shows exactly where every unit of a recalled batch went |
| Vaccines spoil when a fridge fails | Cold-chain temperature logging with mandatory corrective action |

## 1.2 Technology at a glance

- **Server:** native object-oriented PHP 8.2 (no framework), PDO and MySQL/MariaDB, running on Apache (XAMPP during development).
- **Browser:** Bootstrap 5, vanilla JavaScript with `fetch`, Chart.js for charts and html5-qrcode for camera scanning.
- **Documents:** PDF generation for purchase orders, reports and label sheets, plus CSV export.
- **AI:** Google Gemini with function calling. It is used only by the AI assistant, from the server; the key never reaches the browser.

## 1.3 Ground rules the system follows

1. **No fake data.** Every number on screen comes from the database. Forecasts and other estimates are labelled as estimates.
2. **No dead buttons.** If you can see an action, it works. If you lack permission for it, you do not see it, and the server still refuses it if you try.
3. **Stock changes only through transactions.** Every change to stock is a recorded movement, posted inside a database transaction. Nothing is edited "in place".
4. **Humans approve sensitive operations.** These include adjustments, count variances, purchases, transfers and reservations. The person who asks cannot approve their own request (segregation of duties).
5. **The AI cannot act on its own.** It reads data and can only prepare drafts that a human must approve.

---

# Part 2 — Users, roles and permissions

## 2.1 How access works

Access has two layers:

1. **Permission:** *what* you may do, for example "approve stock requests".
2. **Department scope:** *where* you may do it.
   - **Global** roles see and act on every department.
   - **Department** roles are limited to the departments assigned to the user. The limit is applied in the database queries themselves, not just hidden in the screens.

A user has one or more roles plus a list of assigned departments. The Administrator can create new roles and change which permissions any role has under **Administration → Roles & permissions**.

## 2.2 Default roles

| Role | Scope | Purpose |
|---|---|---|
| **System Administrator** | Global | Users, roles, departments, locations, settings and the audit log. Sees the whole hospital, including values and suppliers, read-only. Does **not** handle stock or approve purchases. |
| **Hospital Management** | Global | View-only analysis plus approvals. Cannot create or edit anything, but approves or rejects what others submit: requests, transfers, adjustments, reservations, purchase requests and purchase orders. |
| **Inventory Manager** | Global | Runs the central store: products, receiving, issuing, transfers, counts, recalls, codes and adjustment approval. |
| **Pharmacist** | Department | Pharmacy medicines, dispensing, expiry, requests, cold chain and recalls. |
| **Department Storekeeper** | Department | A ward's own stock: requests, confirming deliveries, recording consumption, returns, damages and counts. |
| **Procurement Officer** | Global | Suppliers, purchase requests and purchase orders. |

## 2.3 Full permission list

| Area | Permission | Allows |
|---|---|---|
| Dashboard | `dashboard.view`, `notifications.view` | See the dashboard and your own notifications |
| Administration | `users.manage` | Create, edit, activate, deactivate and unlock users |
| | `roles.manage` | Create roles and assign permissions |
| | `departments.manage`, `locations.manage` | Manage departments and storage locations |
| | `settings.manage` | Change system settings and thresholds |
| | `audit.view` | Read the audit log |
| Products | `products.view`, `products.manage` | View, register and edit products |
| | `categories.manage` | Categories and units of measure |
| Inventory | `stock.view` | Stock balances and batches |
| | `stock.movements.view` | The movement ledger |
| | `stock.issue` | Dispatch stock to departments |
| | `stock.confirm_receipt` | Confirm what physically arrived |
| | `stock.opening_balance` | Record opening balances (direct receipts) |
| | `stock.reconcile` | Run reconciliation and receive mismatch alerts |
| | `consumption.record` | Record use or dispensing |
| | `adjustments.request`, `adjustments.approve` | Damage, loss and correction adjustments |
| | `counts.perform`, `counts.approve` | Stock counts and approval of their variances |
| | `reservations.manage`, `reservations.approve` | Emergency reservations |
| | `recalls.manage` | Batch recalls |
| | `temperature.record` | Cold-chain readings |
| | `codes.print` | QR codes, barcodes and labels |
| | `department_levels.manage` | Min, reorder and max levels per department |
| Requests & transfers | `requests.create`, `requests.approve` | Department stock requests |
| | `transfers.create`, `transfers.approve` | Transfers and returns |
| Procurement | `suppliers.view`, `suppliers.manage` | Suppliers and their price quotes |
| | `purchase_requests.create`, `purchase_requests.approve` | Purchase requests |
| | `purchase_orders.manage`, `purchase_orders.approve` | Purchase orders |
| | `goods_receipts.create` | Receive goods against a purchase order |
| | `goods_receipts.direct` | Receive goods without a purchase order (donations, opening stock) |
| Analytics | `analytics.view`, `reports.export` | Analytics, reports and their export |
| | `valuation.view` | See money values and costs. Without it, values are hidden everywhere, including in the AI's answers. |
| AI | `ai.use` | Use the assistant |
| | `ai.propose` | Let the AI prepare draft purchase requests and transfers |
| Equipment | `equipment.view`, `equipment.manage` | Reserved for a future equipment module. No equipment screens exist yet. |

## 2.4 Segregation of duties

The system refuses these combinations even if your role has both permissions:

- approving your **own** stock request, adjustment, count, purchase request, purchase order, transfer or reservation;
- the creator of a purchase order approving it;
- removing the **last** active administrator, or removing the permission that lets someone manage users and roles (lock-out prevention).

---

# Part 3 — Core ideas

Read this part once; everything else builds on it.

## 3.1 Products, units and packs

A **product** is anything stocked: a medicine, a consumable or a reagent. Each product has:

- a **base unit**, the smallest unit counted (tablet, vial, piece, ml…). All stock is stored in base units;
- optional **pack units** with a conversion factor, for example 1 BOX = 100 TAB, and optionally their own barcode (GTIN). You can order and receive in boxes; the system converts to tablets;
- flags: batch-tracked, expiry-tracked, **critical** (life-saving, given priority in alerts) and **controlled** (narcotics);
- a storage condition (room temperature, refrigerated, frozen) and a default supplier lead time;
- hospital-wide min, reorder and max levels.

## 3.2 Batches

Every delivery of a product creates a **batch** with:

- a system batch code (for example `B26-000123`);
- the manufacturer's lot number;
- manufacture and expiry dates;
- the unit cost.

Batch statuses:

| Status | Meaning |
|---|---|
| `active` | Usable |
| `quarantined` | Held back, not usable |
| `recalled` | Under recall, not usable |
| `expired` | Past its expiry date, not usable |
| `depleted` | No stock left |

## 3.3 Locations

Stock lives in **storage locations** that belong to departments. Seeded examples are the Central Store main store, cold room and quarantine; the Pharmacy dispensary, refrigerator and controlled-drugs cabinet; and a store for each clinical department. Refrigerator locations have a temperature range (2–8 °C).

There is one special location, **In Transit (`TRANSIT`)**. Stock that has been dispatched but not yet confirmed as received sits here, so it is never "lost" between departments.

Seeded departments:

| Code | Department |
|---|---|
| CS | Central Store |
| PH | Pharmacy |
| ED | Emergency Department |
| LAB | Laboratory |
| MAT | Maternity |
| OT | Operating Theatre |
| OPD | Outpatient Department |
| IPD | Inpatient Wards |
| MES | Medical Equipment Store |

## 3.4 The movement ledger

Every stock change is written as one or more **movements** in an append-only ledger (`stock_movements`). Movement types:

| Type | Meaning |
|---|---|
| RECEIVED | Arrived from a supplier, a donation or an opening balance |
| ISSUED | Dispatched from a store to a department |
| TRANSFERRED | Moved between departments |
| RETURNED | Sent back to the central store |
| CONSUMED | Used on patients or dispensed |
| ADJUSTED_IN / ADJUSTED_OUT | Approved corrections |
| DAMAGED, EXPIRED, WRITTEN_OFF | Losses |
| QUARANTINED | Moved to quarantine |

**Ledger rows can never be edited or deleted**, not by the application and not even directly in the database: triggers and database grants block it. A mistake is corrected by posting a new, opposite movement through an approved adjustment.

**Balances** (`stock_balances`, one row per batch per location) are a fast summary kept in step with the ledger in the same transaction. **Reconciliation** recomputes every balance from the ledger and reports any difference.

## 3.5 Usable stock

"Usable" stock excludes:

- expired batches;
- recalled batches;
- quarantined batches;
- quantities held by active emergency reservations.

Forecasts, low-stock alerts and issuing all work on usable stock.

## 3.6 FEFO — First Expiry, First Out

When stock is issued, transferred or consumed, the system picks batches automatically in order of earliest expiry (`FefoAllocator`). Expired, recalled and quarantined batches, and reserved quantities, are never picked. This is the main defence against expiry waste.

## 3.7 Document numbers

Documents get readable, sequential numbers per year, generated safely even when two people save at the same moment. Examples include `PO-2026-000001` for purchase orders and similar prefixes for requests, issues (`ISS`), transfers (`TRF`), returns (`RTN`) and receipts.

## 3.8 Approvals and statuses

Most workflows have statuses you will see as coloured badges:

| Document | Status flow |
|---|---|
| Stock request | draft → submitted → approved / partially approved / rejected → partially issued → issued → received (or cancelled) |
| Transfer / issue | draft → pending approval → approved / rejected → dispatched → partially received → received (or cancelled) |
| Purchase request | draft → submitted → approved / rejected → partially ordered → ordered (or cancelled) |
| Purchase order | draft → pending approval → approved → sent → partially received → received → closed (or cancelled) |
| Adjustment | pending → approved / rejected → posted |
| Stock count | in progress → submitted → approved (or cancelled) |
| Reservation | pending → active → released / consumed (or rejected) |

---

# Part 4 — Module-by-module user guide

The left menu shows only what your permissions allow. On a phone the menu collapses behind the ☰ button.

## 4.1 Signing in

- Sign in with your username (or email) and password at the login page.
- **First sign-in:** if your password was set by an administrator, you must choose a new one (at least 8 characters) before going further.
- **Timeouts:** you are signed out after **30 minutes of inactivity**, and after **10 hours** regardless.
- **Failed sign-ins:**
  - After 5 failed attempts within 15 minutes for one username, or 20 from one computer, sign-in is paused for a while.
  - After 10 consecutive failures the account is **locked** and an administrator must unlock it.
- If a form says "Session expired", reload the page and submit again. This is the CSRF protection doing its job.

## 4.2 Dashboards (one per role)

Each role has its own dashboard, built around that role's daily work. Everyone sees only figures for the departments they're allowed to see. Every card and button opens a page the user may use; buttons for actions the role can't perform are never shown. Every dashboard has an **Ask the assistant** card with questions suited to the role. Clicking one opens the AI assistant and asks it straight away.

| Dashboard | Who | What it shows |
|---|---|---|
| **Administrator** | System Administrator | **System panel:** active, inactive and locked users; failed sign-ins in the last 24 hours; access-denied attempts in the last 7 days; system health (last reconciliation, alert scan, AI status); the latest audit entries; shortcuts to users, roles, departments, locations, settings and the audit log. **Hospital overview:** stock KPIs, risks, charts, departments and recent movements. The administrator sees everything, including values, but does not handle stock or approve purchases. |
| **Management** | Hospital Management | **Analysis and approvals only, with no create or edit actions.** Shows inventory value, 30-day consumption, 90-day wastage, purchases this month, stock availability, and low, out-of-stock and expiring items. An **Awaiting your approval** list covers stock requests, transfers, adjustments, reservations, purchase requests and purchase orders submitted by others. Also: stock-out and expiry risks, consumption, flow and category charts, and a department comparison. |
| **Inventory operations** | Inventory Manager | Low, out-of-stock, expiring and expired stock; requests to issue; deliveries expected (and overdue); redistribution ideas; anomalies. An **Awaiting your approval** list (requests, transfers, adjustments, counts) and a **Store work** list (requests to issue, purchase orders to receive, stock in transit, counts in progress, open recalls). Stock-out and expiry risks, charts and recent movements. Quick actions: receive, issue, transfer, count, labels, scan. |
| **Procurement** | Procurement Officer | Items to reorder, approved requests waiting for a purchase order, draft and pending purchase orders, open and overdue deliveries, and value received this month compared with last month. Also lists open purchase orders (overdue first), approved requests waiting to be ordered, and the top suppliers by rating. Quick actions: low-stock suggestions, new purchase request, purchase order from requests, suppliers. |
| **Pharmacy** | Pharmacist | Medicines in stock, low and out of stock, expiring and expired stock, units dispensed today and over 7 days. Banners for deliveries to confirm and fridges needing attention. Also: a list of expiring batches (use first), fridge status (last reading, out of range, reading due after 12 hours), stock-out risks, items running low, deliveries to confirm, and the department's requests. |
| **Department store** | Department Storekeeper | Deliveries to confirm, items low or out of stock, items in stock, units used today and over 7 days. Lists of deliveries to confirm, the department's requests and their status, items running low, and expiring batches; fridge status if the department has one. Quick actions: record consumption, confirm deliveries, request stock, return to store, stock count, report damage or loss. |

A user with several roles sees the dashboard of the highest one, in this order: Administrator, Management, Inventory Manager, Procurement, Pharmacist, Storekeeper. Users who hold only custom roles see the general hospital overview, filtered by their permissions.

Opening any dashboard also triggers the alert scan if it has not run in the last 15 minutes.

## 4.3 Notifications

The bell icon lists alerts meant for you:

- low or critical stock;
- expiring and expired batches;
- deliveries waiting for confirmation;
- items waiting for your approval;
- delayed purchase orders;
- anomalies;
- temperature excursions;
- reconciliation mismatches.

Alerts are de-duplicated, so the same alert is not repeated on the same day. Click one to open the related page.

## 4.4 Products (Catalogue)

- **List and search** by name, generic name or code; filter by category and status.
- **Product page** shows details, stock per location, batches in stock with expiry badges, recent movements and the product's forecast summary.
- **Register or edit** (`products.manage`): set the base unit, pack units with factors and barcodes, levels, storage condition, flags, lead time and an optional photo (JPG, PNG or WebP up to 2 MB).
- **Activate or deactivate** instead of deleting. History is always kept.
- **Categories and units of measure** are maintained under Catalogue (`categories.manage`).

## 4.5 Stock on hand and batches

- **Stock on hand:** current usable and total quantities per product and location, filtered by department, category, product or low stock.
- **Batches & expiry:**
  - every batch with its expiry badge (expired, within 30, 90 or 180 days, or none);
  - filters for department and "include empty batches";
  - sortable columns.

## 4.6 Receiving stock

There are two ways stock enters the hospital.

1. **Receive against a purchase order** (`goods_receipts.create`), under Procurement → Receive against PO:
   - Pick an approved or sent PO and enter the delivery note number.
   - For each line, enter the lot number, expiry date, quantity accepted and optionally a quantity rejected with a reason (for example "crushed").
   - **Partial deliveries** are fine; the PO becomes *partially received*.
   - **Over-receipt** beyond the ordered quantity is refused (the tolerance is a setting, 0 % by default).
   - The **same delivery note** cannot be received twice.
   - Each accepted line creates a batch and a RECEIVED movement, with the cost taken from the PO price converted to base units.
2. **Direct receipt** (`goods_receipts.direct` / `stock.opening_balance`), under Inventory → Goods receipts → New: for donations, opening balances or emergency purchases without a PO.

A hidden one-time submission token stops a double click from receiving the same delivery twice. Cold-chain products are marked with a ❄ icon when you receive them.

## 4.7 Issuing stock to departments (stores → departments)

1. A department submits a **stock request** (4.10), or the store issues directly (`stock.issue`).
2. The store creates an **issue**. The system proposes batches using FEFO, and you can see exactly which batches and expiries will leave.
3. On **dispatch**, the stock moves from the store to **In Transit**.
4. The receiving department sees it under **Incoming deliveries** and **confirms receipt** (`stock.confirm_receipt`) of what physically arrived. Confirmed stock moves from Transit into the department's location.
5. **Partial receipt:** if less arrived, confirm the actual quantity. The difference is returned or handled, never silently lost.
6. Stock still in transit after 48 hours (a setting) raises an alert and appears under Anomalies.

## 4.8 Incoming deliveries

This page lists stock sent to your departments that is waiting for your confirmation. Confirm promptly: until you do, the stock is not usable in your department.

## 4.9 Department stock and levels

- **Department stock** shows each department's holdings, usable quantity, 30-day consumption and a level badge (out of stock, low or OK).
- With `department_levels.manage`, set **min, reorder and max levels per department per product**. Choose "Whole catalogue" to add items the department should stock. These levels drive department alerts, forecasts and redistribution.

## 4.10 Stock requests

- **Create** (`requests.create`): choose the destination location and priority (routine, urgent or emergency), then add products and quantities. You can also scan items with the camera or a USB scanner (4.17).
- **Review** (`requests.approve`): approve in full, approve part (adjust quantities) or reject with a reason. You cannot approve your own request.
- **Fulfil:** the store issues against the approved quantities, in one or several issues. Progress is tracked per line. "Close remaining" ends a request that will not be completed.
- **Receive:** the requesting department confirms delivery (4.7).

## 4.11 Transfers and returns

- **Transfer:** move stock between two departments (`transfers.create`), approved by someone with `transfers.approve`.
- **Return to store:** send unneeded or near-expiry stock back to the Central Store.
- Both follow the same path: approval → dispatch (stock goes to Transit) → receiving confirmation.
- **Redistribution** transfers come from the Redistribution page (6.3) or from the AI (6.6), and are always created *awaiting approval*.

## 4.12 Consumption

Record stock used on patients or dispensed (`consumption.record`): choose a location, then the products and quantities. Batches are chosen by FEFO. Each record creates CONSUMED movements. **Accurate consumption is what makes forecasts accurate**, so record it daily.

## 4.13 Adjustments

Use adjustments for damage, loss, theft, breakage, found stock or correction (`adjustments.request`):

- Enter the batch, location, quantity, direction (in or out) and a reason.
- An approver (`adjustments.approve`, a different person) approves or rejects it. Only an approved adjustment posts movements.
- The setting `allow_negative_adjustment_without_count` controls whether a decrease is allowed without a recent count.

## 4.14 Stock counts (physical inventory)

1. **Start a count** for a location (`counts.perform`).
2. Counts are **blind**: the counter does not see the system quantity, so they cannot simply copy it.
3. Enter the counted quantities per batch. You can scan batch labels to jump to the right line.
4. **Submit.** The system shows the variances.
5. An approver (`counts.approve`) approves, and the variances are posted as adjustment movements. Large or repeated discrepancies appear under Anomalies.

## 4.15 Emergency reservations

These set stock aside for an expected emergency or a specific patient or procedure (`reservations.manage`):

- Approval (`reservations.approve`) makes the reservation **active**. Reserved quantities are excluded from usable stock, so FEFO will not issue them to someone else.
- A reservation ends when it is **released** (no longer needed) or **consumed** (used).

## 4.16 Recalls

When a manufacturer or regulator recalls a lot (`recalls.manage`):

1. Search for the batch by code, lot number or product and start a recall with the reason.
2. The batch becomes **recalled**: unusable everywhere at once.
3. The recall page shows **where every remaining unit is** and the **full distribution history**: which departments received it and how much was consumed. This tells you which patients may have been affected.
4. Remaining stock is quarantined or returned and the recall is closed.

## 4.17 QR codes, barcodes and scanning

- **Labels** (`codes.print`): generate QR codes and barcodes for products and batches. Print a label sheet in the browser or download it as a PDF.
- **Scan page:** scan with the device camera, a USB or Bluetooth barcode scanner (which acts like a keyboard), or type the code manually. The result opens the product or batch.
- **Scan inside forms:** in requests, issues, consumption, receipts and counts, scanning adds the item or jumps to its line.
- The supplier's own barcode (GTIN) on a pack unit is recognised too.

The camera requires HTTPS (or `localhost`) because browsers only allow camera access on secure pages.

## 4.18 Cold chain

For refrigerated locations (`temperature.record`):

- Record temperature readings.
- A reading **outside the allowed range (for example 2–8 °C)** is refused unless you enter the **corrective action** taken. It then raises an alert.
- The page shows reading history and charts per location. Locations that are not cold-chain locations cannot receive readings.

## 4.19 Procurement

1. **Suppliers** (`suppliers.manage`):
   - contact details, payment terms, default lead time and status;
   - **product quotes**: supplier SKU, quoted price, validity, lead time and preferred supplier.
2. **Purchase requests (PR)** (`purchase_requests.create`):
   - Create manually, or from **Low-stock suggestions**. These list items at or below their reorder level, with a suggested quantity (up to the max level) and the preferred supplier.
   - A PR is approved by someone with `purchase_requests.approve`.
3. **Purchase orders (PO)** (`purchase_orders.manage`):
   - Create from approved PR lines (they are grouped by supplier) or from scratch.
   - Edit the draft: order units such as boxes, prices and tax.
   - Submit for approval → approved by a **different** person (`purchase_orders.approve`) → mark as **sent** to the supplier → download the **PDF**.
   - Cancelling a PO releases the PR quantities so they can be ordered again.
4. **Receive** against the PO (4.6).
5. **Supplier performance:** on-time rate, average lead time, fill rate, rejection rate and an overall rating out of 5:
   `rating = 5 × (40 % on-time + 30 % fill rate + 30 % acceptance)`.
   Small samples show their PO count so you do not over-trust them.

### The purchase flow: who does what

A purchase passes between three roles. The **Procurement Officer** prepares it, the **Hospital Manager** approves it, and the **Inventory Manager** receives the goods. The person who asks to spend money is never the one who approves it, and neither of them receives the goods. The system enforces this.

```
 PROCUREMENT OFFICER          HOSPITAL MANAGER            INVENTORY MANAGER
 ───────────────────          ────────────────            ─────────────────
 1. Purchase request    ───▶  2. Approve request
                                     │
 3. Purchase order      ◀────────────┘
                        ───▶  4. Approve order
                                     │
 5. Send PDF to supplier ◀───────────┘
         │
         └──────────── supplier delivers ──────────▶  6. Receive the goods
                                                       7. Issue to departments ──▶ DEPARTMENT
                                                                                   8. Confirm receipt
```

**Before the first purchase (one-time setup):**
- **Inventory Manager:** registers the product under **Products → New** (unit, pack sizes, reorder level).
- **Procurement Officer:** registers the supplier and its product quotes (price, lead time, preferred supplier) under **Suppliers**.

| # | User | Where in MediStock | What they do | Status after |
|---|---|---|---|---|
| 1 | Procurement Officer | **Purchase requests → Low-stock suggestions** (or "Items to reorder" on the dashboard) | Tick the items, adjust quantities, write a justification and **Submit** | PR **Submitted** |
| 2 | Hospital Manager | **"Awaiting your approval"** on the Management dashboard | **Approve**, or **Reject** with a reason | PR **Approved** |
| 3 | Procurement Officer | **Purchase orders → From approved requests** | One draft PO per supplier is created. Check units (for example boxes), prices, tax and the expected date, then **Submit for approval**. | PO **Pending approval** |
| 4 | Hospital Manager | **"Awaiting your approval"** | **Approve**, or **Reject** with a reason (the PO's creator can never approve it) | PO **Approved** |
| 5 | Procurement Officer | The purchase order page | **Download PDF**, send it to the supplier, click **Mark as sent** | PO **Sent** |
| 6 | Inventory Manager | **Receive against PO** (or "Deliveries expected" on the dashboard) | Enter the delivery note, then per line: lot number, expiry date, quantity accepted, and any rejected quantity with a reason. **Post** it. | PO **Partially received** / **Received**; batches created; stock in the Central Store |
| 7 | Inventory Manager | **Stock issues** (against department requests) | Issue the stock; batches are picked by earliest expiry (FEFO) | Stock **in transit** |
| 8 | Pharmacist / Storekeeper | **Incoming deliveries** | Confirm what physically arrived | Stock in the department |

**Variations and safeguards:**
- **Who can start step 1:** a Pharmacist or the Inventory Manager can also create the purchase request. The Hospital Manager still approves it.
- **The AI assistant:** "Prepare a purchase request for …" only creates a **draft** PR. It still goes through steps 2–8.
- **Late deliveries:** a PO past its expected date shows as **overdue** on the Procurement and Inventory dashboards, and an alert is sent.
- **Cancelling:** if the supplier can't deliver, cancel the PO with a reason. The PR quantities are released so they can be ordered from another supplier.
- **Receiving safeguards:** the same delivery note can't be received twice, and you can't receive more than was ordered.
- **Donations or emergency stock without a PO:** the Inventory Manager uses a **direct receipt** (**Goods receipts → New**) and skips steps 1–5.
- **The System Administrator** takes no part in purchasing.

## 4.20 Analytics and reports

- **Overview:** KPIs (stock value, purchases received, consumption, wastage, stock-outs) with trend charts, filtered by date range and department.
- **Nine reports**, each with filters (dates, department, category, grouping), a chart, and **PDF and CSV export** (`reports.export`):

  | Report | Shows |
  |---|---|
  | Inventory valuation | Value of stock at purchase cost |
  | Consumption | What was used, by department, product, category or month |
  | Department comparison | Departments side by side |
  | Purchasing expenditure | Spending on purchases |
  | Supplier performance | Supplier delivery metrics |
  | Expiry & wastage | Expired, damaged and written-off stock |
  | Low stock & stock-out risk | Items that are low or forecast to run out |
  | Slow-moving inventory | Stock with no movement in 30–365 days |
  | Stock movement history | Ledger activity over a period |

- Money columns appear only with `valuation.view`. CSV files are protected against spreadsheet formula injection.

## 4.21 Movement ledger and reconciliation

- **Movement ledger** (`stock.movements.view`): every movement, filterable by product, batch, location, type, user and date. This is the system's full history.
- **Reconciliation** (`stock.reconcile`): recompute all balances from the ledger. "All balances reconcile" is the normal result. It also runs nightly from cron, and users with this permission are notified of any mismatch.

## 4.22 The AI assistant

Open the floating chat button on any page, or the full **AI assistant** page (`ai.use`). Ask in plain language, for example:

- "Which items are low in Maternity?"
- "What will expire in the next 60 days?"
- "When will we run out of amoxicillin?"
- "Compare consumption by department this quarter."
- "Prepare a purchase request for 2000 paracetamol tablets." (needs `ai.propose`)

The assistant answers only from live system data, using your permissions and departments, and labels forecasts as estimates. It **never changes stock**: "prepare" requests create **drafts awaiting human approval**, shown as links in its reply. Conversation history is kept per user. Usage limits apply: 10 messages per minute and 100 per day by default. See 6.6 for how it works.

---

# Part 5 — Administration

## 5.1 Users

Under **Administration → Users** (`users.manage`):

- create a user with a full name, username, email, roles and departments, and a temporary password (the user must change it at first sign-in);
- edit, deactivate or reactivate, **unlock** a locked account, or reset a password;
- department roles only work for departments assigned to the user.

## 5.2 Roles and permissions

Create custom roles and tick permissions by module. The six system roles can be adjusted, but the system blocks changes that would leave nobody able to manage users and roles. Re-running the seeder never removes permissions an admin granted.

## 5.3 Departments and locations

- **Departments:** code, name, type and active status. Exactly one is the Central Store.
- **Locations:** belong to a department; types are store, refrigerator, cabinet, quarantine and transit. Cold-chain locations have a minimum and maximum temperature.

## 5.4 System settings

| Setting | Default | Meaning |
|---|---|---|
| `hospital_name`, `hospital_address`, `hospital_phone` | General Hospital | Shown on PDFs and labels |
| `currency_code` | USD | Currency for money values |
| `default_lead_time_days` | 14 | Supplier lead time when the product and supplier have none |
| `internal_lead_time_days` | 2 | Store → department supply time (department forecasts) |
| `safety_stock_days` | 7 | Safety stock when history is too short for statistics |
| `review_period_days` | 14 | How far ahead "reorder soon" looks |
| `expiry_warning_days` / `expiry_critical_days` | 90 / 30 | Expiry badge and alert thresholds |
| `redistribution_cover_days` | 90 | Cover above which department stock counts as surplus |
| `transit_alert_hours` | 48 | When stock stuck in transit is flagged |
| `anomaly_sigma` | 3 | Sensitivity of consumption spike detection (standard deviations) |
| `overreceipt_tolerance_pct` | 0 | Allowed receipt above the PO quantity |
| `allow_negative_adjustment_without_count` | 1 | Allow decreases without a recent count |
| `notify_low_stock`, `notify_expiry`, `notify_delayed_delivery`, `notify_anomalies` | on | Which alert types are generated |
| `ai_enabled`, `ai_per_minute_limit`, `ai_daily_message_limit` | on / 10 / 100 | AI assistant on/off and usage limits |

## 5.5 Audit log

**Administration → Audit log** (`audit.view`) records:

- sign-ins and failed sign-ins;
- user, role and settings changes;
- approvals and rejections;
- denied access attempts;
- reconciliation runs and other sensitive actions;

each with the user, IP address, time and before/after details. It is **append-only**; nobody can edit or delete entries.

---

# Part 6 — Intelligence and AI

All intelligence is calculated by transparent formulas from the ledger. Every result shows its assumptions and a confidence level.

## 6.1 Stock-out forecasting

For each product, either hospital-wide or for one department:

1. **Average daily consumption (ADC)** from completed days, up to yesterday:
   `ADC = 0.6 × (average of last 30 days) + 0.4 × (average of last 90 days)`.
   Recent use counts more, but a single odd month does not dominate.
2. **Days remaining** = usable stock ÷ ADC. **Expected run-out date** = today + days remaining.
3. **Lead time:**
   - hospital-wide, the supplier lead time (product → preferred supplier → system default);
   - per department, the internal supply time.
4. **Safety stock:**
   - with enough history, `1.65 × σ × √lead time`, which is about a 95 % service level, where σ is the daily variation;
   - otherwise `safety_stock_days × ADC`.
5. **Reorder point** = ADC × lead time + safety stock.
6. **Urgency:**
   - **critical** when stock lasts less than the lead time (it will run out before a new order arrives);
   - **reorder now** when at or below the reorder point;
   - **reorder soon** when within the review period;
   - otherwise **sufficient**.
7. **Suggested order quantity** brings stock up to cover the lead time plus review period plus safety stock.
8. **Confidence** is high, medium or low depending on how much history exists.

Worked example: 10 tablets per day with no variation, a 10-day lead time and 300 usable tablets.

- Safety stock = 7 × 10 = 70.
- Reorder point = 100 + 70 = 170.
- 300 is above 170 but within 170 + 10 × 14 = 310, so the result is **reorder soon**, with 30 days remaining.

## 6.2 Expiry risk (FEFO simulation)

For each location, the system simulates using stock in FEFO order at that location's actual usage rate, and estimates **how much of each batch will still be left on its expiry date**.

Example: 500 units expiring in 30 days, used at 5 per day. About 150 will be used, so **about 350 units are at risk**.

Suggestions follow: move the stock to a department that uses it faster, return it to store, or use it first.

## 6.3 Smart redistribution

The system finds departments holding **surplus** (cover above `redistribution_cover_days`, or stock at expiry risk) and departments that **need** the same product (below their reorder level or forecast to run out). It suggests transfers that rescue expiring stock and prevent shortages, preferring the batches that expire soonest. One click creates a transfer **awaiting approval**.

## 6.4 Anomaly detection

The system flags patterns worth a human look:

- **Consumption spikes:** a day's use above the normal level by more than `anomaly_sigma` standard deviations.
- **Repeated losses:** frequent damage, loss or write-off for a product or location.
- **Count discrepancies:** large or repeated count variances.
- **Stuck stock:** stock in transit longer than `transit_alert_hours`.
- **Delayed deliveries:** purchase orders past their expected date.

An anomaly is a prompt to investigate, not an accusation.

## 6.5 Alerts

`alerts:run` (cron every 15 minutes, or automatically from the dashboard) creates de-duplicated notifications. They go to the people whose permissions and departments make each alert relevant. For example, low stock in Maternity goes to Maternity staff and central store managers, not to everyone.

## 6.6 The AI assistant — how it works

1. Your question goes to the MediStock server (never directly from your browser to Google).
2. The server sends it to **Google Gemini** together with descriptions of **17 tools**. The model never receives the database, only the results of tools it asks for.
3. **Read tools (15):**
   - total inventory;
   - low-stock items and out-of-stock items;
   - expiring batches and expired batches;
   - department stock;
   - product search;
   - stock movements;
   - inventory value;
   - consumption analysis;
   - slow-moving items;
   - supplier performance;
   - stock-out predictions, expiry predictions and anomalies.
4. **Proposal tools (2):** `prepare_purchase_request` and `prepare_stock_transfer`. These create drafts or pending items for human approval, and only when you explicitly ask.
5. Every tool call is checked:
   - the arguments are validated (types, limits, allowed values);
   - your **permissions and department scope** are enforced, exactly as on the screens;
   - money values are removed if you lack `valuation.view`.
6. **Prompt-injection defence:** data coming back from tools (for example a product name containing "ignore your instructions") is treated as data, never as instructions. The reply is rendered with a safe Markdown renderer, so it cannot inject scripts.
7. **Reliability:**
   - the default model is `gemini-3.5-flash-lite`, with automatic fallback to other models if one is overloaded;
   - at most 5 tool rounds per question;
   - messages are limited to 2000 characters;
   - the last 20 messages are kept as context.
8. If the AI service fails after a draft was already created, you still get a reply listing that draft. Actions are never hidden from you.
9. If no API key is configured, or `ai_enabled` is off, the assistant is disabled and everything else works normally.

---

# Part 7 — Installation, deployment and operation

## 7.1 Requirements

- **Web server:** Apache 2.4 with `mod_rewrite`, `mod_headers` and `AllowOverride All`.
- **PHP:** 8.2 or newer, with the extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json`, `curl`, `gd` and `dom`.
- **Database:** MySQL 8 or MariaDB 10.4+.
- **Composer:** needed to install the PHP dependencies.

## 7.2 Installation (development, XAMPP on Linux)

```bash
cd /opt/lampp/htdocs/MediStock
composer install
cp .env.example .env                              # edit DB_*, APP_URL, APP_TIMEZONE
/opt/lampp/bin/php bin/console key:generate       # paste the value into APP_KEY
/opt/lampp/bin/php bin/console migrate            # creates the database and tables
/opt/lampp/bin/php bin/console db:seed            # roles, permissions, departments, units, settings, admin
chmod -R a+rwX storage                            # Apache (user "daemon") must write here
chmod 644 .env                                    # Apache must be able to read it on XAMPP
```

Open `http://localhost/MediStock` and sign in as `admin` / `Admin@12345`. You will be asked to change the password.

Optional development data (never in production):

```bash
/opt/lampp/bin/php bin/console db:seed --demo-users   # one account per role, password Demo@12345
/opt/lampp/bin/php bin/console db:seed --demo-data    # 30 common hospital products
/opt/lampp/bin/php bin/console demo:generate --days=365  # a year of realistic history (APP_ENV=development only)
```

Always use XAMPP's PHP (`/opt/lampp/bin/php`) on the command line so it matches the web server.

## 7.3 The `.env` file

| Key | Purpose |
|---|---|
| `APP_ENV` | `production` or `development` |
| `APP_DEBUG` | `true` shows detailed errors (development only) |
| `APP_URL`, `APP_TIMEZONE`, `APP_KEY` | Base URL, timezone and a random secret |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection |
| `SESSION_*` | Cookie name, idle and absolute timeouts, secure-cookie mode |
| `GEMINI_API_KEY` | Gemini key (server-side only; leave empty to disable the AI) |
| `GEMINI_MODEL`, `GEMINI_FALLBACK_MODELS`, `GEMINI_TIMEOUT`, `GEMINI_THINKING_LEVEL` | AI model behaviour |

Never commit `.env` or share it. It contains the database password and the API key.

## 7.4 Production deployment (summary)

The README has the full version with the Apache configuration.

1. Copy the code without `.env`, `storage/` contents or `tests/`. Run `composer install --no-dev --optimize-autoloader`.
2. Point the Apache `DocumentRoot` at `public/`. Serve **HTTPS only** and redirect HTTP to HTTPS.
3. Configure `.env`: `APP_ENV=production`, `APP_DEBUG=false`, an `https://` URL and a new `APP_KEY`.
4. Run `migrate` and `db:seed` (no demo options). Then apply `database/production_grants.sql` with real passwords and set the app to use the restricted `medistock_app` account.
5. Set permissions: `.env` mode 640 with the web-server group, and `storage/` writable by the web-server group.
6. Install the cron jobs (7.5).
7. Run `php bin/console system:check` until it reports **0 failures**. Then sign in and change the admin password.

## 7.5 Scheduled jobs (cron)

```
*/15 * * * *  /opt/lampp/bin/php /opt/lampp/htdocs/MediStock/bin/console alerts:run
15 2 * * *    /opt/lampp/bin/php /opt/lampp/htdocs/MediStock/bin/console stock:reconcile
```

Run them as the web-server user so the files they create stay writable for Apache.

## 7.6 Console commands

| Command | What it does |
|---|---|
| `migrate` | Create the database if needed and run pending migrations |
| `migrate:status` | Show which migrations have run |
| `migrate:fresh` | Drop everything and re-migrate. **Destroys all data**; asks for confirmation. |
| `db:seed [--demo-users] [--demo-data]` | Reference data (safe to re-run) |
| `user:create` | Create a user interactively |
| `key:generate` | Print a new `APP_KEY` |
| `stock:reconcile` | Check balances against the ledger (nightly) |
| `alerts:run` | Generate notifications (every 15 minutes) |
| `system:check` | Health and deployment check; exits with 1 if anything fails |
| `demo:generate --days=N` | Development only: back-dated demo history |

## 7.7 Daily and periodic operation

| When | Task |
|---|---|
| Daily | Departments record consumption and confirm incoming deliveries. Check notifications. Fridge readings are recorded at least twice a day. |
| Weekly | Review the stock-out forecast, expiry risk, redistribution suggestions and anomalies. Act on low-stock purchase suggestions. |
| Monthly | Cycle counts for high-value or critical items. Review analytics and supplier performance. |
| Quarterly / yearly | A full stock count per location. Review roles and user access, and deactivate leavers. |
| Nightly (automatic) | Reconciliation and backups. |

## 7.8 Backups

- **Database:** take a nightly `mysqldump --single-transaction --routines --triggers medistock`. The `--triggers` option matters, because it keeps the ledger protection.
- **Files:** back up `storage/uploads/` (product photos) and `.env`.
- **Storage:** keep copies off the server and **test a restore** regularly.

---

# Part 8 — Security

| Threat | Protection |
|---|---|
| Stolen passwords | `password_hash`, minimum length, forced change of temporary passwords, sign-in rate limits, account lock |
| Session hijacking | HttpOnly and SameSite cookies (Secure on HTTPS), ID regenerated at sign-in, strict mode, idle and absolute timeouts, browser binding |
| Cross-site request forgery | A CSRF token on every POST and fetch request |
| Cross-site scripting | All output escaped with `e()`; a strict Content-Security-Policy with no inline scripts; safe Markdown for AI replies |
| SQL injection | Prepared statements with bound parameters everywhere; identifiers checked against a strict pattern |
| Unauthorised access | Every route declares a permission (enforced by an automated test); department scope applied in SQL; services re-check permissions |
| Tampering with history | Append-only ledger and audit log, enforced by application code, database triggers **and** database grants |
| Insider fraud | Segregation of duties, approvals, blind counts, anomaly detection, audit log |
| Malicious uploads | Real MIME type check plus image decoding, size limit, random names, stored outside the web root |
| Spreadsheet attacks | CSV export neutralises formula injection |
| Information leaks | `.env`, code, `storage/` and `vendor/` blocked from the web; `X-Powered-By` removed; generic error pages in production; the API key never sent to the browser |
| Clickjacking and sniffing | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS |
| Open redirects | Post-login redirect accepts only same-application paths |
| AI misuse | Tools are permission- and scope-checked, arguments validated, drafts only, rate limits, prompt-injection-resistant design |

---

# Part 9 — Developer guide

## 9.1 Folder structure

```
MediStock/
├── app/
│   ├── Console/        Console commands (Console.php, SystemCheck.php)
│   ├── Controllers/    HTTP controllers, grouped by module (thin: validate → call service → respond)
│   ├── Core/           Mini framework: App, Container, Router, Route, Request, Response, Database,
│   │                   Session, Validator, Gate, View, Config, Logger, Paginator, ExceptionHandler
│   ├── Exceptions/     Business, authorisation, validation and HTTP exceptions
│   ├── Helpers/        Global helper functions (e(), url(), route(), money(), qty(), badges, cache_bump())
│   ├── Middleware/     SecureHeaders, StartSession, VerifyCsrfToken, Authenticate, RedirectIfAuthenticated, RequirePermission
│   ├── Repositories/   All SQL lives here or in services' query classes
│   └── Services/       Business logic: Stock, Department, Procurement, Intelligence, Codes, Reporting,
│                       Ai, ColdChain, Catalogue, Admin, Dashboard, Demo, Support
├── bin/console         CLI entry point
├── config/             app, database, security, ai, permissions (role matrix), navigation
├── database/
│   ├── migrations/     0001–0013 SQL files (schema, views, indexes, ledger triggers)
│   ├── seeds/          Roles/permissions, departments/locations, categories/units, settings, admin, demo
│   └── production_grants.sql
├── docs/               ARCHITECTURE.md (design), PROJECT_GUIDE.md (this file)
├── public/             Web root: index.php (front controller), .htaccess, assets (css, js, vendor)
├── resources/views/    PHP templates (layouts, partials, one folder per module)
├── routes/             web.php (HTML routes), api.php (JSON routes)
├── storage/            logs, sessions, cache, uploads, reports (writable, never web-accessible)
├── tests/              PHPUnit: Unit and Integration
└── vendor/             Composer dependencies
```

## 9.2 Request lifecycle

1. Apache rewrites every request to `public/index.php`.
2. `App::boot()` loads `.env` and the config, builds the DI container and router, and registers the routes.
3. Global middleware runs: **SecureHeaders → StartSession → VerifyCsrfToken**. Then the route middleware runs: **auth → can:permission**.
4. The controller (autowired by the container) validates input with `Validator` and calls a **service**.
5. The service enforces business rules and permissions (`Gate`), and uses repositories or `Database` with prepared statements, inside `Database::transaction()` for anything that writes.
6. The controller returns a `View` (HTML) or `json()` response. `ExceptionHandler` turns exceptions into friendly pages or JSON errors: 403 for authorisation, 422 for validation, and 403 "Session expired" for CSRF.

## 9.3 Golden rules for changing code

1. **Only `StockService::post()` changes stock.** It writes the transaction, its movements and the balances atomically, and refuses negative balances. Never write to `stock_balances` or `stock_movements` directly.
2. **Every new route** goes inside the `auth` group with `->middleware('can:<permission>')`. `RouteSecurityTest` fails otherwise. New permissions go in `config/permissions.php`; then run `db:seed`.
3. **Department scope:** use `Gate::departmentScope('<perm>')`. `null` means all departments; an array means only those. Apply it in the SQL.
4. **Approvals:** use `ApprovalRules::assertDifferentUser()` for anything one person requests and another approves.
5. **Output:** always escape with `e()` in views. No inline `<script>`, because the CSP blocks it; put JavaScript in `public/assets/js/pages/`.
6. **SQL:** bound parameters only. Use `Database::inClause()` for lists and `Database::like()` for searches.
7. **Cache:** if you add a change that affects analytics, call `cache_bump()`. It runs after commit.
8. **Schema changes:** add a new numbered migration file and never edit an old one. The runner splits on `;` at the end of a line, so keep trigger bodies on a single line.
9. **Audit:** log sensitive actions with `AuditService::log()`.

## 9.4 Key classes

| Class | Role |
|---|---|
| `Core\Database` | PDO wrapper: `fetchAll`, `fetchOne`, `fetchValue`, `insert`, `update`, `transaction` (nesting joins), `afterCommit` |
| `Core\Gate` | `can`, `canAny`, `authorize`, `departmentScope` |
| `Services\Stock\StockService` | The only writer of stock |
| `Services\Stock\FefoAllocator` | Picks batches by earliest expiry, excluding unusable or reserved stock |
| `Services\Stock\ReceivingService`, `IssueService`, `ReconciliationService` | Receipts, issue/transfer through transit, reconciliation |
| `Services\Department\*` | Requests, transfers, consumption, adjustments, counts, reservations, recalls, department levels |
| `Services\Procurement\*` | Suppliers, purchase requests, purchase orders, supplier performance |
| `Services\Intelligence\ForecastMath`, `ExpirySimulator` | Pure, unit-tested maths |
| `Services\Intelligence\ForecastService`, `ExpiryService`, `RedistributionService`, `AnomalyService`, `AlertService` | Data and cached results |
| `Services\Ai\AssistantService`, `GeminiClient`, `ToolRegistry`, `ArgValidator`, `MarkdownRenderer` | AI assistant |
| `Services\Support\CacheService` | Versioned JSON file cache |
| `Services\Dashboard\DashboardResolver`, `RoleDashboardService` | Pick the dashboard for the user's role and build its data |
| `Services\Support\DocumentNumberService` | Sequential document numbers |

## 9.5 Database highlights

- **Engine:** every table is InnoDB with foreign keys (110 foreign keys). CHECK constraints keep balances ≥ 0, movement quantities > 0 and batch dates valid.
- **Ledger triggers (migration 0013):** these block any UPDATE or DELETE on `stock_movements` and `audit_logs`. The only exception is backdating `created_at` with `SET @medistock_allow_backdate = 1`, which the demo generator and tests use.
- **Views:** `v_batch_stock`, `v_product_stock`, `v_department_stock` and `v_daily_consumption`.
- **Indexes:** covering indexes for consumption and balance queries (migration 0012).

## 9.6 Tests

```bash
/opt/lampp/bin/php vendor/bin/phpunit
```

- **110 tests.** They cover validation, routing, helpers, the forecast and expiry maths, AI safety, the cache, the stock flow and FEFO, department workflows, procurement, intelligence, codes, reports, AI tools, permissions, route security, data integrity and the role dashboards (each role gets its own dashboard and every link on it opens for that role).
- **Isolation:** integration tests run against the configured database inside a transaction that is rolled back, and need `db:seed --demo-users`. The cache is disabled during tests.
- **Gemini:** the tests never call it live. AI tools are tested directly.

## 9.7 Performance notes

- **Measured speed:** with about 47,000 ledger rows, pages render in 80–200 ms.
- **Analytics cache:** forecasts, expiry risk, redistribution, anomalies, dashboard charts and the analytics overview are cached for up to 10 minutes. Any relevant change invalidates the cache immediately after commit.

---

# Part 10 — Troubleshooting and FAQ

| Problem | Cause and fix |
|---|---|
| "Session expired" when submitting a form | The page was open too long, so the CSRF token expired. Reload and resubmit. |
| Can't sign in: "too many attempts" | Wait 15 minutes, or ask an admin to unlock the account (Users → Unlock). |
| A menu item or button is missing | Your role lacks the permission, or the item belongs to another department. Ask the admin. |
| "You are not assigned to this department" | Department roles act only on assigned departments. The admin can assign more. |
| Stock shows in the ledger but not in my department | It is **in transit**. Confirm it under Incoming deliveries. |
| An issue won't take a batch I can see | That batch is expired, recalled, quarantined or reserved, so it is not usable. |
| The forecast says "low confidence" | Too little consumption history. Record consumption daily and confidence improves. |
| The AI says it's busy or unavailable | Gemini is overloaded. Try again in a minute; the system already tries fallback models. |
| The AI is not shown at all | No `GEMINI_API_KEY`, `ai_enabled` is off, or you lack `ai.use`. |
| Money values show "—" or are missing | You do not have `valuation.view`. |
| The camera scanner doesn't start | The page must be on HTTPS (or `localhost`) and camera permission must be allowed. A USB scanner or manual entry also works. |
| Settings in `.env` seem ignored | The web server cannot read `.env`. On XAMPP use mode 644; in production use 640 with the web-server group. |
| Blank page or 500 error | Check `storage/logs/app-YYYY-MM-DD.log`, check `storage/` permissions, and run `system:check`. |
| Reconciliation reports a mismatch | Someone changed balances outside the system. Investigate with the ledger and audit log, then correct with an approved adjustment. |
| Notifications not appearing | Check the cron job for `alerts:run`, or open the dashboard (it runs alerts every 15 minutes). `system:check` shows when alerts last ran. |

**Can I delete a product, batch or movement?** No. History must be preserved for safety and audit. Deactivate products; correct stock with adjustments.

**Can the AI order medicine on its own?** No. It can only prepare drafts; a person with the right permission must approve them, and the normal workflow continues from there.

**Does MediStock connect to "Medi AI" or any other system?** No. Its only external connection is Google Gemini, used by the assistant.

---

# Part 11 — Glossary

| Term | Meaning |
|---|---|
| **ADC** | Average daily consumption |
| **Base unit** | The smallest counted unit of a product; all stock is stored in it |
| **Batch / lot** | Stock from one delivery with the same lot number and expiry |
| **Blind count** | A count where the counter cannot see the expected quantity |
| **Cold chain** | Keeping temperature-sensitive products within their range (for example 2–8 °C) |
| **FEFO** | First Expiry, First Out: use the soonest-expiring stock first |
| **GTIN** | The global barcode number on a manufacturer's pack |
| **Lead time** | Days from ordering until stock arrives |
| **Ledger** | The append-only list of every stock movement |
| **PO / PR** | Purchase order / purchase request |
| **Reconciliation** | Checking that balances equal the sum of ledger movements |
| **Reorder point** | The stock level at which a new order should be placed |
| **Safety stock** | Extra stock that covers variable demand during the lead time |
| **Segregation of duties** | The requester and the approver must be different people |
| **Transit** | The virtual location holding stock dispatched but not yet received |
| **Usable stock** | Stock that is not expired, recalled, quarantined or reserved |

---

*MediStock Intelligence v1.0. For the original design decisions see [ARCHITECTURE.md](ARCHITECTURE.md); for installation commands see the [README](../README.md).*
