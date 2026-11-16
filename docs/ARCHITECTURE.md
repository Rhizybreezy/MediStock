# MediStock Intelligence — System Design (v1, pending approval)

Smart Hospital Inventory & Resource Management System
Stack: native OOP PHP 8.2 · PDO · MySQL/MariaDB (InnoDB) · Bootstrap 5 · vanilla JS (Fetch) · Chart.js · Google Gemini API

Target runtime: XAMPP on this machine (PHP 8.2.12, MariaDB 10.4.32). The code also targets MySQL 8.0+.

---

## Table of contents

1. [System architecture](#1-system-architecture)
2. [Database schema & relationships](#2-database-schema--relationships)
3. [Inventory workflows](#3-inventory-workflows)
4. [Authentication & permission architecture](#4-authentication--permission-architecture)
5. [Intelligence engine (forecasting, expiry, anomalies)](#5-intelligence-engine)
6. [Gemini AI integration architecture](#6-gemini-ai-integration-architecture)
7. [Project folder structure](#7-project-folder-structure)
8. [Phased implementation roadmap](#8-phased-implementation-roadmap)
9. [Decisions requiring approval](#9-decisions-requiring-approval)

---

## 1. System architecture

### 1.1 Layered design

```
Browser (Bootstrap 5 + vanilla JS + Chart.js + html5-qrcode)
   │  HTML pages (server-rendered PHP views)  +  JSON via fetch() for AJAX
   ▼
public/index.php  ── Front controller (the only public PHP entry point)
   │
   ├─ Router            routes/web.php (pages), routes/api.php (JSON)
   ├─ Middleware chain  SecureHeaders → Session → Auth → Csrf → Permission → Throttle
   ▼
Controllers   thin: read the request, call validation, call a service, return a View or JSON
   ▼
Services      business rules + transactions (StockService, ProcurementService,
              ForecastService, ExpiryService, GeminiService, NotificationService …)
   ▼
Repositories  all SQL lives here; PDO prepared statements only
   ▼
Models        plain entity/value objects (Product, Batch, StockMovement …)
   ▼
MySQL (InnoDB, utf8mb4, FKs, CHECK constraints, transactions)
```

**Rules that keep it maintainable**

| Rule | Why |
|---|---|
| Controllers never write SQL; repositories never make business decisions. | One place to change each concern. |
| **Only `StockService` may change stock.** It writes `stock_movements` and updates `stock_balances` in the same transaction. | Stock numbers stay correct and traceable. |
| Each multi-row operation runs in a single `Database::transaction(fn)` call, with `SELECT … FOR UPDATE` on the balance rows it touches. | Stock cannot be oversold or lost if two users act at the same moment. |
| Services check permissions too, not only middleware (`Gate::authorize('stock.issue', $departmentId)`). | AI tools, CLI jobs and controllers all go through the same check. |
| Every page and endpoint is a route with a declared permission. | No protected action without an authorization check. |

### 1.2 Core infrastructure classes (`app/Core`)

| Class | Responsibility |
|---|---|
| `App` | Bootstraps env/config, error handler, container, router. |
| `Container` | Minimal dependency-injection container (constructor autowiring through reflection). |
| `Router` | Method + path matching, route params, middleware per route/group, named routes. |
| `Request` / `Response` / `JsonResponse` | Input access (typed getters), redirects, JSON envelopes. |
| `Database` | PDO singleton (`ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, utf8mb4), `transaction()`, helpers. |
| `View` | PHP templates with layouts and sections; `e()` escaping helper used everywhere. |
| `Session` | Secure cookie flags, regeneration, flash messages, idle/absolute timeouts. |
| `Validator` | Rule-based server-side validation (`required|integer|min:1|exists:products,id`). |
| `Gate` | Permission + department-scope checks (see §4). |
| `Config` / `Env` | Reads `.env` (outside the public directory) and `config/*.php`. |
| `Logger` | PSR-3-style file logger → `storage/logs/app-YYYY-MM-DD.log`. |
| `ExceptionHandler` | Safe user-facing errors (no stack traces in production) and logs the full detail. |

### 1.3 Domain services (`app/Services`)

| Service | Purpose |
|---|---|
| `StockService` | The **only** writer of stock: `receive`, `dispatch`, `confirmReceipt`, `consume`, `adjust`, `writeOff`, `reserve`. Applies the FEFO allocator. |
| `FefoAllocator` | Given product + location + qty, returns batch allocations in First-Expiry-First-Out order. It skips expired, recalled and quarantined batches and stock held by reservations. |
| `StockRequestService` | Department request → review → approve/partial/reject → issue → confirm receipt. |
| `TransferService` | Department-to-department transfers and returns to central store. |
| `ProcurementService` | PR → approval → PO → goods receipt → batch registration. |
| `AdjustmentService` / `StockCountService` | Counts, variances, approval-gated adjustments. |
| `ForecastService` | Consumption rates, stock-out prediction, reorder quantities (§5). |
| `ExpiryService` | Expiry risk simulation (FEFO-aware) and redistribution suggestions. |
| `AnomalyService` | Unusual consumption, repeated adjustments, count discrepancies. |
| `RecallService` | Batch recall, trace and quarantine. |
| `NotificationService` | Creates de-duplicated alerts and runs the scheduled scans. |
| `ReportService` + `PdfExporter` / `CsvExporter` | Analytics datasets and exports. |
| `CodeService` | QR/barcode generation and decoding of scanned payloads. |
| `AuditService` | Writes `audit_logs` (who, what, before/after, IP). |
| `Ai\GeminiClient`, `Ai\AssistantService`, `Ai\ToolRegistry`, `Ai\Tools\*` | AI assistant (§6). |
| `DocumentNumberService` | Gap-safe numbering (`PO-2026-000042`) using a locked sequence row. |

### 1.4 Third-party libraries (installed with Composer, no framework)

| Need | Library |
|---|---|
| Autoload | Composer PSR-4 (`App\` → `app/`) |
| `.env` loading | `vlucas/phpdotenv` |
| QR generation | `chillerlan/php-qrcode` |
| 1D barcode generation (Code 128) | `picqer/php-barcode-generator` |
| PDF reports/labels | `dompdf/dompdf` |
| Spreadsheet export | Native CSV (UTF-8 BOM, opens cleanly in Excel / LibreOffice) |
| Tests (dev only) | `phpunit/phpunit` |
| Front end | Bootstrap 5.3, Bootstrap Icons, Chart.js 4, html5-qrcode. **Files are served locally** from `public/assets/vendor/` because hospital networks are often offline or filtered. |
| Gemini | Direct HTTPS calls through PHP cURL (no SDK needed) |

### 1.5 Request/response conventions

- Pages: `GET /products`, `GET /products/{id}`; forms post to the same resource paths with a `_csrf` token.
- AJAX/JSON: `/api/...`, always returning `{ "ok": true, "data": …, "meta": {pagination} }` or `{ "ok": false, "error": { "code", "message", "fields" } }`.
- Data tables use server-side search, sort and pagination (`?q=&sort=name&dir=asc&page=2&per_page=25`) through one reusable JS component (`ms-table.js`). Sort columns are whitelisted on the server.
- Toasts, confirmation modals and loading states come from one shared `ms-ui.js` module.

---

## 2. Database schema & relationships

Conventions: InnoDB, `utf8mb4_unicode_ci`, `id BIGINT UNSIGNED AUTO_INCREMENT` PKs (INT for small lookup tables), `created_at`/`updated_at` timestamps, snake_case, FK names `fk_<table>_<col>`. **All quantities are stored in the product's base unit** as `DECIMAL(14,3)`, which allows fractional units such as litres. Money is stored as `DECIMAL(14,2)`. The currency is a system setting.

### 2.1 Entity groups at a glance

```
IDENTITY & ACCESS        users ─< user_roles >─ roles ─< role_permissions >─ permissions
                         users ─< user_departments >─ departments
                         login_attempts, system_settings, audit_logs

ORGANISATION             departments ─< inventory_locations (stores, shelves, fridges, quarantine)
                         departments ─< department_product_settings >─ products

CATALOGUE                inventory_categories ─< products >─ units_of_measure
                         products ─< product_units (pack conversions + GTIN barcodes)

BATCHES & STOCK          products ─< product_batches >─ suppliers
                         product_batches ─< stock_balances >─ inventory_locations   (CURRENT STATE)
                         stock_transactions ─< stock_movements                       (LEDGER, append-only)
                         stock_reservations, batch_recalls

DEPARTMENT WORKFLOWS     stock_requests ─< stock_request_items
                         stock_transfers ─< stock_transfer_items
                         stock_adjustments ─< stock_adjustment_items
                         stock_counts ─< stock_count_items

PROCUREMENT              suppliers ─< supplier_products >─ products
                         purchase_requests ─< purchase_request_items
                         purchase_orders ─< purchase_order_items  (item → PR item)
                         goods_receipts ─< goods_receipt_items   (item → PO item, → batch)

INTELLIGENCE & OPS       notifications, temperature_logs, equipment ─< equipment_maintenance
AI                       ai_conversations ─< ai_messages
SUPPORT                  document_sequences
```

### 2.2 Table definitions

Legend: **PK**, **FK→table**, **UQ** unique, **IX** index, **CK** check constraint.

#### Identity & access

**users**: id PK · full_name · username UQ · email UQ · phone NULL · password_hash VARCHAR(255) · status ENUM(active, inactive, locked) · must_change_password BOOL · last_login_at · last_login_ip · failed_login_count · locked_until NULL · created_by FK→users NULL · timestamps
**roles**: id PK · code UQ (`admin`, `management`, `inventory_manager`, `pharmacist`, `storekeeper`, `procurement`) · name · description · **scope** ENUM(global, department) · is_system BOOL (system roles cannot be deleted) · timestamps
**permissions**: id PK · code UQ (`stock.issue`) · module · description
**role_permissions**: role_id FK · permission_id FK · PK(role_id, permission_id)
**user_roles**: user_id FK · role_id FK · PK(user_id, role_id). Many-to-many, so one person can be both pharmacist and storekeeper.
**user_departments**: user_id FK · department_id FK · is_primary BOOL · PK(user_id, department_id)
**login_attempts**: id PK · username · ip_address VARBINARY(16) · succeeded BOOL · attempted_at · IX(username, attempted_at) · IX(ip_address, attempted_at)
**system_settings**: key PK · value TEXT · type ENUM(int, decimal, bool, string, json) · group · description · updated_by FK→users · updated_at. Holds thresholds such as `expiry_warning_days=90`, `expiry_critical_days=30`, `safety_stock_days=7`, `review_period_days=14`, `currency=…`, `ai_daily_message_limit`.
**audit_logs**: id PK · user_id FK NULL · action (`create`, `update`, `approve`, `login`, `ai_tool_call`…) · entity_type · entity_id · old_values JSON NULL · new_values JSON NULL · ip_address · user_agent · created_at · IX(entity_type, entity_id) · IX(user_id, created_at). Append-only: the app's DB user has no UPDATE/DELETE grant on this table in production.

#### Organisation

**departments**: id PK · code UQ · name UQ · type ENUM(central_store, pharmacy, clinical, laboratory, theatre, equipment_store, other) · is_central_store BOOL · head_user_id FK→users NULL · is_active · timestamps. Seeded: Central Store, Pharmacy, Emergency, Laboratory, Maternity, Operating Theatre, OPD, Inpatient Wards, Medical Equipment Store.
**inventory_locations**: id PK · department_id FK · code UQ · name · type ENUM(store, shelf, refrigerator, freezer, cabinet, quarantine, transit) · is_cold_chain BOOL · min_temp_c NULL · max_temp_c NULL · is_active · timestamps. Each department has at least one location. One system-wide **Transit** location holds stock that has been dispatched but not yet received. Each store has a **Quarantine** location.
**department_product_settings**: id PK · department_id FK · product_id FK · min_level · reorder_level · max_level · is_stocked BOOL · UQ(department_id, product_id). Per-department thresholds; when no row exists, product defaults apply.

#### Catalogue

**inventory_categories**: id PK · parent_id FK→self NULL · code UQ · name · requires_expiry BOOL (default for new products) · is_active. Seeded with the 8 required categories.
**units_of_measure**: id PK · code UQ (`TAB`, `BTL`, `BOX`, `PCS`, `PKT`, `L`, `ML`, `CTN`, `VIAL`, `AMP`) · name · allows_decimal BOOL
**products**: id PK · product_code UQ (system code, e.g. `MED-000123`) · name · generic_name NULL · category_id FK · base_unit_id FK→units_of_measure · dosage_form NULL · strength NULL · manufacturer NULL · description TEXT NULL · storage_condition ENUM(room_temp, cool, refrigerated, frozen, controlled) · storage_notes NULL · is_batch_tracked BOOL · is_expiry_tracked BOOL · is_critical BOOL (eligible for emergency reservation and priority alerts) · is_controlled BOOL · min_level · reorder_level · max_level · default_lead_time_days · image_path NULL · is_active · created_by FK · timestamps · IX(name) · IX(category_id, is_active) · CK(min_level ≤ reorder_level ≤ max_level)
**product_units**: id PK · product_id FK · unit_id FK · factor_to_base DECIMAL(14,3) (1 BOX = 100 TAB) · gtin_barcode NULL UQ (manufacturer barcode) · is_purchase_unit · is_issue_unit · UQ(product_id, unit_id)

#### Batches & stock (the heart of the system)

**product_batches**: id PK · product_id FK · **batch_code** UQ (system ID used in QR codes, e.g. `B26-000781`) · lot_number (manufacturer's batch number) · supplier_id FK NULL · goods_receipt_item_id FK NULL · manufacture_date NULL · expiry_date NULL · unit_cost DECIMAL(14,4) · quantity_received · received_at · status ENUM(active, quarantined, recalled, expired, depleted) · notes · timestamps · UQ(product_id, lot_number, supplier_id) · IX(product_id, expiry_date) · IX(expiry_date, status) · CK(expiry_date IS NULL OR manufacture_date IS NULL OR expiry_date > manufacture_date)

> A batch's *available quantity* is **not** stored on the batch row. It is `SUM(stock_balances.quantity)` for that batch, exposed through the `v_batch_stock` view. Storing it on the batch too would create two sources of truth. The spec's per-batch "storage location" is also handled through balances, because one batch can sit in several locations at once (part in central store, part in maternity).

**stock_balances** (current state, a cache of the ledger): id PK · location_id FK · batch_id FK · product_id FK (denormalized for fast product totals, enforced to match batch) · quantity DECIMAL(14,3) · last_movement_id FK→stock_movements · updated_at · **UQ(location_id, batch_id)** · IX(product_id, location_id) · **CK(quantity ≥ 0)**

**stock_transactions** (header: one business operation): id PK · txn_number UQ · type ENUM(receipt, issue_dispatch, issue_receipt, transfer_dispatch, transfer_receipt, return, consumption, adjustment, write_off, expiry_disposal, recall_quarantine, count_correction, opening_balance) · reference_type (`goods_receipt`, `stock_request`, `stock_transfer`, `stock_adjustment`, `stock_count`, `batch_recall`, NULL) · reference_id NULL · department_id FK · performed_by FK→users · approved_by FK→users NULL · approval_reference NULL · reason NULL · notes NULL · created_at · IX(reference_type, reference_id) · IX(department_id, created_at)

**stock_movements** (ledger, **append-only**): id PK · transaction_id FK · product_id FK · batch_id FK · movement_type ENUM(RECEIVED, ISSUED, TRANSFERRED, RETURNED, CONSUMED, ADJUSTED_IN, ADJUSTED_OUT, DAMAGED, EXPIRED, WRITTEN_OFF, QUARANTINED) · from_location_id FK NULL · to_location_id FK NULL · quantity DECIMAL(14,3) **CK(quantity > 0)** · unit_cost (snapshot for valuation) · performed_by FK · reason NULL · created_at · IX(product_id, created_at) · IX(batch_id) · IX(from_location_id, created_at) · IX(to_location_id, created_at) · IX(movement_type, created_at) · CK(from_location_id IS NOT NULL OR to_location_id IS NOT NULL)

> `from = NULL` means stock entering the hospital (receipt, positive adjustment). `to = NULL` means stock leaving it (consumption, write-off, negative adjustment). Both set means an internal move. Mistakes are fixed with **reversal entries**, never by editing or deleting rows.

**stock_reservations**: id PK · location_id FK · product_id FK · batch_id FK NULL · quantity · purpose (e.g. "Emergency: mass-casualty reserve") · status ENUM(pending, active, released, consumed) · reserved_by FK · approved_by FK NULL · valid_until NULL · timestamps · IX(location_id, product_id, status). Reserved stock is excluded from normal FEFO allocation, so `available = on_hand − active reservations`.

**batch_recalls**: id PK · recall_number UQ · batch_id FK · reason TEXT · severity ENUM(low, medium, high, critical) · source (manufacturer, regulator, internal) · status ENUM(open, in_progress, closed) · initiated_by FK · closed_by FK NULL · timestamps. The list of affected locations is *derived* from `stock_movements` and `stock_balances`.

#### Department workflows

**stock_requests**: id PK · request_number UQ · requesting_department_id FK · supplying_department_id FK (normally Central Store) · priority ENUM(routine, urgent, emergency) · status ENUM(draft, submitted, approved, partially_approved, rejected, issued, partially_issued, received, cancelled) · requested_by FK · reviewed_by FK NULL · reviewed_at · review_notes · needed_by NULL · timestamps · IX(status, requesting_department_id)
**stock_request_items**: id PK · stock_request_id FK · product_id FK · requested_qty CK>0 · approved_qty NULL · issued_qty DEFAULT 0 · received_qty DEFAULT 0 · notes · UQ(stock_request_id, product_id) · CK(issued_qty ≤ approved_qty)
**stock_transfers**: id PK · transfer_number UQ · type ENUM(transfer, return_to_store, redistribution) · source_department_id FK · source_location_id FK · destination_department_id FK · destination_location_id FK · status ENUM(draft, pending_approval, approved, rejected, dispatched, received, cancelled) · source ENUM(manual, ai_proposal, redistribution_engine) · requested_by FK · approved_by FK NULL · approved_at · dispatched_by FK NULL · dispatched_at · received_by FK NULL · received_at · reason · timestamps · CK(source_location_id <> destination_location_id)
**stock_transfer_items**: id PK · stock_transfer_id FK · product_id FK · batch_id FK NULL (chosen at dispatch by FEFO if not specified) · requested_qty · approved_qty NULL · dispatched_qty DEFAULT 0 · received_qty DEFAULT 0
**stock_adjustments**: id PK · adjustment_number UQ · location_id FK · reason_code ENUM(damaged, expired, lost, theft_suspected, count_variance, data_correction, found, write_off) · status ENUM(pending, approved, rejected, posted) · stock_count_id FK NULL · requested_by FK · approved_by FK NULL · approved_at · notes · timestamps
**stock_adjustment_items**: id PK · stock_adjustment_id FK · batch_id FK · quantity_change DECIMAL signed CK(≠ 0) · reason
**stock_counts**: id PK · count_number UQ · location_id FK · type ENUM(full, cycle, spot) · status ENUM(in_progress, submitted, approved, cancelled) · started_by FK · submitted_at · approved_by FK NULL · approved_at · timestamps
**stock_count_items**: id PK · stock_count_id FK · batch_id FK · system_qty (snapshot when counted) · counted_qty · variance (generated column = counted − system) · counted_by FK · counted_at · UQ(stock_count_id, batch_id)

> **Consumption records.** The spec lists a `consumption_records` table. I propose not creating it: a consumption is a `stock_transactions` row of type `consumption` with its `stock_movements` lines (type `CONSUMED`). That header already holds department, user, time, reason and notes, so a separate table would duplicate it. Returns to the central store are `stock_transfers` of type `return_to_store`.

#### Procurement

**suppliers**: id PK · supplier_code UQ · name · contact_person · phone · email · address · tax_number NULL · payment_terms · default_lead_time_days · status ENUM(active, inactive, blacklisted) · rating_cache DECIMAL(3,2) NULL · notes · timestamps
**supplier_products**: id PK · supplier_id FK · product_id FK · supplier_sku NULL · quoted_unit_price · quote_valid_until NULL · lead_time_days NULL · is_preferred BOOL · UQ(supplier_id, product_id)
> Pricing history is derived from `purchase_order_items` (actual prices paid, with dates) together with quote changes recorded in `audit_logs`. There is no duplicate price-history table.

**purchase_requests**: id PK · pr_number UQ · department_id FK · source ENUM(manual, reorder_suggestion, ai_proposal) · priority · status ENUM(draft, submitted, approved, rejected, partially_ordered, ordered, cancelled) · justification · requested_by FK · approved_by FK NULL · approved_at · rejection_reason · timestamps
**purchase_request_items**: id PK · purchase_request_id FK · product_id FK · quantity CK>0 · estimated_unit_cost · preferred_supplier_id FK NULL · ordered_qty DEFAULT 0 · notes · UQ(purchase_request_id, product_id)
**purchase_orders**: id PK · po_number UQ · supplier_id FK · status ENUM(draft, pending_approval, approved, sent, partially_received, received, closed, cancelled) · order_date · expected_delivery_date · delivery_location_id FK · subtotal · tax · total · created_by FK · approved_by FK NULL · approved_at · notes · timestamps · IX(status, expected_delivery_date)
**purchase_order_items**: id PK · purchase_order_id FK · product_id FK · purchase_request_item_id FK NULL · order_unit_id FK · unit_factor · ordered_qty_base · unit_price · line_total · received_qty_base DEFAULT 0 · CK(received_qty_base ≤ ordered_qty_base × (1 + over-receipt tolerance)). One PR can split across several POs by supplier, so the PR link sits on the line.
**goods_receipts**: id PK · grn_number UQ · purchase_order_id FK NULL (NULL allowed for donations or opening stock; requires the extra permission `goods_receipts.direct`) · supplier_id FK NULL · receiving_location_id FK · delivery_note_number NULL · invoice_number NULL · status ENUM(draft, posted, cancelled) · **submission_token UQ** (blocks double-submit) · received_by FK · posted_at · notes · timestamps · UQ(supplier_id, delivery_note_number) (blocks receiving the same delivery note twice)
**goods_receipt_items**: id PK · goods_receipt_id FK · purchase_order_item_id FK NULL · product_id FK · lot_number · manufacture_date · expiry_date · qty_received · qty_rejected DEFAULT 0 · rejection_reason · unit_cost · batch_id FK NULL (set on posting)

#### Intelligence, operations, AI, support

**notifications**: id PK · user_id FK · type (`low_stock`, `out_of_stock`, `expiry_warning`, `expired`, `request_pending`, `pr_pending`, `delivery_delayed`, `anomaly`, `stockout_risk`, `temperature`, `recall`) · severity ENUM(info, warning, critical) · title · message · link_url · entity_type · entity_id · **dedupe_key** · is_read · read_at · created_at · UQ(user_id, dedupe_key) · IX(user_id, is_read, created_at)
**temperature_logs**: id PK · location_id FK · temperature_c DECIMAL(5,2) · humidity_pct NULL · recorded_at · recorded_by FK · is_out_of_range (computed on insert from the location range) · corrective_action NULL · IX(location_id, recorded_at)
**equipment**: id PK · asset_tag UQ · product_id FK NULL · name · serial_number UQ NULL · model · manufacturer · department_id FK · location_id FK NULL · status ENUM(in_service, under_maintenance, out_of_service, disposed) · purchase_date · purchase_cost · warranty_until · maintenance_interval_days · next_maintenance_due · timestamps
**equipment_maintenance**: id PK · equipment_id FK · type ENUM(preventive, corrective, calibration, inspection) · performed_at · performed_by_user_id FK NULL · vendor NULL · cost · findings · next_due_date · timestamps
**ai_conversations**: id PK · user_id FK · title · is_archived · timestamps · IX(user_id, updated_at)
**ai_messages**: id PK · conversation_id FK ON DELETE CASCADE · role ENUM(user, model, function_call, function_result) · content MEDIUMTEXT · function_name NULL · function_args JSON NULL · function_status ENUM(ok, denied, invalid, error) NULL · prompt_tokens · output_tokens · latency_ms · created_at · IX(conversation_id, id)
**document_sequences**: name PK (`PO`, `PR`, `GRN`, `REQ`, `TRF`, `ADJ`, `CNT`, `TXN`, `BATCH`, `RCL`) · year · next_value. Read with `FOR UPDATE` inside the business transaction.

#### Views (read-only helpers)

- `v_batch_stock`: batch, product, total on hand, days to expiry, status.
- `v_product_stock`: product, hospital-wide on hand, value, low/out-of-stock flags.
- `v_department_stock`: department × product on hand vs department thresholds.
- `v_daily_consumption`: product × department × day consumption, the input for forecasting.

Table count: 44 tables and 4 views. That covers every table in the brief except `consumption_records`, which is intentionally merged (see above), plus `user_roles`, `department_product_settings`, `product_units`, `stock_transactions`, `stock_reservations`, `batch_recalls`, `stock_adjustment_items`, `supplier_products`, `login_attempts`, `system_settings` and `document_sequences`.

### 2.3 How stock balances are maintained and reconciled

1. **The ledger is the source of truth.** `stock_movements` is append-only. Every quantity that has ever moved is recorded with who, when, where from, where to, why and the approval reference.
2. **Balances are a transactional cache.** `StockService::post(Transaction $t, array $lines)` runs these steps inside a single DB transaction:
   - Lock the affected `stock_balances` rows (`SELECT … FOR UPDATE`, in a consistent order: location_id then batch_id, to avoid deadlocks).
   - Check sufficient quantity at the source, excluding reserved stock where relevant. Reject expired or recalled batches for outward issue.
   - Insert the `stock_transactions` header and `stock_movements` lines.
   - Decrease the source balance and increase the destination balance (`INSERT … ON DUPLICATE KEY UPDATE`).
   - The DB-level `CHECK (quantity >= 0)` is a second safety net.
   - Commit, or roll back everything.
3. **Reconciliation.** `php bin/console stock:reconcile` (scheduled nightly and also runnable from the admin page) recomputes for every (location, batch) pair:
   `Σ qty where to_location = L` − `Σ qty where from_location = L`
   and compares the result with `stock_balances.quantity`. Mismatches are reported to the admin and audit log. They are **never auto-corrected**: the fix is an approved `data_correction` adjustment, so the ledger stays honest.
4. **Physical reality.** Stock counts compare `counted_qty` with system quantity. Variances become a pending `stock_adjustment` that someone other than the counter must approve.
5. **Valuation** = Σ(balance.quantity × batch.unit_cost), which is FIFO/actual-cost valuation per batch.

---

## 3. Inventory workflows

### 3.1 Procurement to stock

```
Purchase Request (draft → submitted)
   │  approver ≠ requester  [purchase_requests.approve]
   ▼
Approved PR ──► Purchase Order(s), grouped by supplier (draft → pending_approval → approved → sent)
   │  [purchase_orders.approve]
   ▼
Goods Receipt (draft): scan or enter lot, expiry and qty per PO line; reject damaged qty
   │  validation: qty ≤ outstanding PO qty (+ tolerance), expiry in the future,
   │  unique submission_token, unique delivery note per supplier
   ▼
Post GRN (one transaction):
   create product_batches → StockService RECEIVED into receiving location
   → update po_items.received_qty → PO status partially_received / received
   → audit + notifications ("New stock received")
```

### 3.2 Department request → issue → receipt

```
Storekeeper/Pharmacist submits request (dept-scoped)   [requests.create]
   ▼
Inventory Manager reviews: approve / partially approve (approved_qty per line) / reject
   [requests.approve]; reviewer cannot approve their own request
   ▼
Issue: FefoAllocator proposes batches (earliest non-expired first); the manager may
   override with a reason; StockService: Central → Transit (ISSUED)
   ▼
Department confirms receipt (full or with discrepancy note): Transit → Dept location
   ▼
Request status → received; any shortfall stays visible as "issued but not received"
```

The Transit step gives two-person accountability. Stock that was dispatched but never received stays visible instead of disappearing.

### 3.3 Transfers and returns

The flow is the same pattern: draft → pending_approval → approved → dispatched (source → Transit) → received (Transit → destination). Returns to the central store use `type = return_to_store`. The redistribution engine and the AI create transfers only as **drafts or proposals**, and a human approves them.

### 3.4 Consumption, damage, expiry, write-off

| Operation | Approval? | Movement |
|---|---|---|
| Record consumption or dispensing | No (permission + department scope) | CONSUMED, location → NULL |
| Report damaged stock | Yes (adjustment) | DAMAGED |
| Dispose of expired stock | Yes | first QUARANTINED (→ quarantine location), then EXPIRED on disposal |
| Write-off / loss / correction | Yes, approver ≠ requester | WRITTEN_OFF / ADJUSTED_IN / ADJUSTED_OUT |
| Recall | Recall manager | QUARANTINED for every location holding the batch; batch status = recalled |

Expired batches are blocked from issue, transfer and consumption at the service layer. They appear only in expiry and disposal screens, clearly marked.

### 3.5 Scanning

- **Product QR** payload: `MSP:<product_code>`, for example `MSP:MED-000123`.
- **Batch QR** payload: `MSB:<batch_code>`, for example `MSB:B26-000781`. Batch labels also carry a Code-128 barcode of the same payload.
- Manufacturer GTIN barcodes are matched through `product_units.gtin_barcode`.
- `GET /api/scan/resolve?code=…` returns `{type: product|batch|unknown, …details, stock at the user's permitted locations}`. Every scan screen (receive, issue, consume, count, lookup) uses this endpoint. A manual search box is always shown as the fallback.

---

## 4. Authentication & permission architecture

### 4.1 Authentication

- `password_hash(PASSWORD_DEFAULT)` and `password_verify`, with `password_needs_rehash` checked on login.
- Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, custom name, `use_strict_mode`. `session_regenerate_id(true)` on login and on privilege change. Idle timeout of 30 min and absolute timeout of 10 h (configurable). Logout destroys the session server-side.
- Session fixation protection and a session fingerprint (user agent family) check.
- **Rate limiting**: after 5 failures per username or 20 per IP within 15 minutes, a temporary lock. The account locks after 10 consecutive failures until an admin unlocks it. All attempts are logged in `login_attempts`.
- New users get a temporary password with `must_change_password = 1`.
- CSRF: a per-session token is required on every POST/PUT/PATCH/DELETE (`_csrf` field or `X-CSRF-Token` header for fetch). Compared with `hash_equals`.
- Headers: `Content-Security-Policy` (self-hosted assets only, no inline JS), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, HSTS when HTTPS.
- XSS: every output goes through `e()` (`htmlspecialchars` with ENT_QUOTES, UTF-8). JS renders text with `textContent`, never `innerHTML`, for data.
- Uploads (product images): MIME sniffed with `finfo`, extension whitelist, size limit, random filename, stored outside the web root and served through a controller.

### 4.2 Authorization model: permission × scope

```
can(user, permission, departmentId = null):
   1. user has a role that grants `permission`?              else DENY
   2. permission is department-bound and departmentId given:
        if any granting role has scope = global → ALLOW
        else ALLOW only if departmentId ∈ user_departments
```

- `Gate::authorize()` throws `AuthorizationException`, which produces a 403 page or JSON error and an audit entry.
- Repositories accept a `DepartmentScope` object, so list queries are **filtered in SQL** (`WHERE department_id IN (…)`). Department users therefore never receive other departments' rows, even in AJAX responses.
- **Segregation of duties** is enforced in services: the approver must not be the requester for stock requests, PRs, POs, adjustments and count approvals.
- Role permissions are editable from the Admin → Roles screen. System roles cannot be deleted, and the `admin` role cannot remove its own `roles.manage` permission (lockout prevention).

### 4.3 Permission catalogue (seeded) and default role matrix

| Module | Permissions | Admin | Mgmt | Inv Mgr | Pharm | Store | Proc |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard | `dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Users/roles | `users.manage`, `roles.manage` | ✓ | | | | | |
| Settings | `settings.manage`, `departments.manage`, `locations.manage` | ✓ | | (loc) | | | |
| Audit | `audit.view` | ✓ | ✓ | | | | |
| Products | `products.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| | `products.manage`, `categories.manage` | | | ✓ | | | |
| Stock view | `stock.view` (dept-scoped) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Receive | `goods_receipts.create`, `goods_receipts.direct` | | | ✓ | | | |
| Issue | `stock.issue` | | | ✓ | ✓* | | |
| Requests | `requests.create` | | | ✓ | ✓ | ✓ | |
| | `requests.approve` | | ✓ | ✓ | | | |
| Receive issued | `stock.confirm_receipt` | | | ✓ | ✓ | ✓ | |
| Transfers | `transfers.create` / `transfers.approve` | | ✓(appr) | ✓ / ✓ | ✓ / | ✓ / | |
| Consumption | `consumption.record` | | | ✓ | ✓ | ✓ | |
| Adjustments | `adjustments.request` / `adjustments.approve` | | ✓(appr) | ✓ / ✓ | ✓ / | ✓ / | |
| Counts | `counts.perform` / `counts.approve` | | | ✓ / ✓ | ✓ / | ✓ / | |
| Reservations | `reservations.manage` / `reservations.approve` | | ✓(appr) | ✓ | ✓ | | |
| Recalls | `recalls.manage` | | | ✓ | ✓ | | |
| Temperature | `temperature.record` | | | ✓ | ✓ | ✓ | |
| Suppliers | `suppliers.view` / `suppliers.manage` | | ✓ / | ✓ / | | | ✓ / ✓ |
| Purchase req. | `purchase_requests.create` / `.approve` | | ✓(appr) | ✓ / | ✓ / | | ✓ / |
| Purchase orders | `purchase_orders.manage` / `.approve` | | ✓(appr) | | | | ✓ / |
| Analytics | `analytics.view`, `reports.export` | ✓ | ✓ | ✓ | (dept) | (dept) | (proc) |
| Valuation | `valuation.view` | | ✓ | ✓ | | | ✓ |
| Equipment | `equipment.view` / `equipment.manage` | ✓ | ✓ | ✓ / ✓ | | ✓ / | |
| AI | `ai.use` / `ai.propose` | ✓ | ✓ | ✓ / ✓ | ✓ / ✓ | ✓ | ✓ / ✓ |

\* Pharmacist issue/dispense is limited to the Pharmacy department scope. Pharmacist and Storekeeper are **department-scoped** roles; all others are **global**. The matrix is only the seed default; the admin can change it.

---

## 5. Intelligence engine

All calculations are deterministic PHP and SQL, and **every prediction returns its assumptions** (rate used, window, data days, lead time, confidence). The UI and the AI show estimates with an "Estimate" badge, separate from actual quantities.

### 5.1 Demand (consumption) definition

- **Department demand** = CONSUMED movements out of that department's locations.
- **Hospital demand** for a product = sum of department demand. If a department has not recorded consumption, net issues into that department (issues − returns) are used as a proxy, and the result is flagged `proxy=true` in the assumptions.
- Source: `v_daily_consumption`, which fills zero-consumption days so averages are not inflated.

### 5.2 Average daily consumption (ADC)

```
avg30 = consumption last 30 days / 30
avg90 = consumption last 90 days / 90
ADC   = 0.6·avg30 + 0.4·avg90            (if ≥ 90 days of history)
      = avg over available days          (if 14–89 days; confidence = medium)
      = insufficient data                (if < 14 days; no prediction, says so)
seasonal factor (only if ≥ 12 months history): month_avg / annual_avg, clamped 0.5–2.0
σ_d   = std deviation of daily consumption (90 days)
```

Confidence label: **high** (≥ 90 days, coefficient of variation < 0.5), **medium**, **low**, or **insufficient**.

### 5.3 Stock-out prediction & reorder

```
usable_stock   = on_hand (non-expired, non-recalled) − active reservations
days_remaining = usable_stock / ADC
stockout_date  = today + days_remaining
LT             = supplier lead time (preferred supplier → product default → setting)
safety_stock   = z·σ_d·√LT   (z = 1.65 ≈ 95% service level, if σ available)
               else safety_stock_days · ADC
reorder_point  = ADC·LT + safety_stock
on_order       = outstanding approved-PO qty (with expected delivery date)
reorder_qty    = max(0, ADC·(LT + review_period) + safety_stock − usable_stock − on_order),
                 capped at max_level and rounded up to the purchase pack size
urgency        = CRITICAL  if usable_stock = 0, or days_remaining < LT and no PO arrives in time
                 HIGH      if usable_stock ≤ reorder_point
                 MEDIUM    if usable_stock ≤ reorder_point + ADC·review_period
                 OK        otherwise
```

Pending POs are checked against their expected date: a PO arriving *after* the predicted stock-out date does not cancel the CRITICAL flag.

### 5.4 Expiry risk (FEFO-aware simulation)

The brief's single-batch formula is correct (500 units, 30 days, ADC 5/day → 150 consumed → ~350 at risk). With several batches, though, earlier-expiring batches absorb demand first. The engine therefore simulates per product and location:

```
sort non-expired batches by expiry_date ASC
cursor_day = 0
for each batch:
    days_until_expiry = expiry_date − today
    available_days    = max(0, days_until_expiry − cursor_day)
    expected_use      = min(batch_qty, ADC · available_days)
    at_risk_qty       = batch_qty − expected_use
    cursor_day       += expected_use / ADC
    at_risk_value     = at_risk_qty · unit_cost
```

The output lists each batch with expected consumption, quantity at risk, value at risk, the assumptions used, and a **suggested action** for human review (redistribute to department X, which uses Y/day; return to supplier if the contract allows; prioritize in FEFO). The engine never suggests using expired stock.

### 5.5 Smart redistribution

For each product: departments with **surplus** (days of cover > 2× max-cover setting, or at-risk expiry qty > 0) are matched with departments that have a **deficit** (below their reorder level, or consuming faster than their stock lasts). Suggested quantities never leave the source below its own reorder level. The engine produces suggestions, and a single click turns one into a *draft* transfer that needs normal approval.

### 5.6 Anomaly detection

- **Consumption spike**: a day's consumption > mean + 3σ (90-day window, minimum 30 days of data).
- **Repeated adjustments**: ≥ 3 negative adjustments of the same product or location within 30 days, or by the same user.
- **Count discrepancy**: |variance| > max(5% of system qty, threshold) for a batch.
- **Unreceived transfers**: items sitting in Transit for longer than N hours.
- **Off-hours movements** (optional): outward movements outside configured hours.

Anomalies raise `anomaly` notifications. They are presented as "worth reviewing", never as accusations.

### 5.7 Scheduled jobs

`php bin/console alerts:run` runs every 15 minutes through cron (or Windows Task Scheduler), plus a throttled on-demand check when the dashboard loads. It covers low/out-of-stock, expiry windows, marking expired batches, delayed POs (past expected date), pending approvals, stock-out risk, temperature excursions and anomalies. Notifications are de-duplicated with `dedupe_key`, for example `low_stock:product:12:dept:3:2026-11-13`.

---

## 6. Gemini AI integration architecture

**Independence.** MediStock has its own `GeminiClient`, its own configuration (`GEMINI_API_KEY`, `GEMINI_MODEL` in MediStock's `.env`), its own conversation tables and its own tool layer. It has **no** connection, import, endpoint call or shared database with Medi AI.

### 6.1 Request flow

```
Browser (chat widget / AI page)
   │ POST /api/ai/chat {conversation_id, message} + CSRF
   ▼
AiController → Gate(ai.use) → rate limit (per-user per-minute + per-day) → length limit
   ▼
AssistantService
   1. Load last N messages of this conversation (owner check)
   2. Build request: system instruction + history + user message
      + function declarations FILTERED to the tools this user may call
   3. GeminiClient → POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
      (API key in the x-goog-api-key header, server-side cURL, timeout, retry on 429/5xx with backoff)
   4. If the response contains functionCall(s):
        ToolRegistry::execute(name, args, user)
          → tool exists? → Gate::can(tool.permission)? → validate args against schema
          → run fixed PHP method (repository/service; prepared statements; dept scope applied)
          → return compact, structured JSON (row limit, numbers labeled actual/estimate)
        send functionResponse back to Gemini; repeat (max 5 rounds)
   5. Final text → sanitize (rendered as escaped Markdown subset) → store → return
   ▼
Browser renders the answer, plus "Data sources: get_low_stock_items" chips and proposal cards
```

### 6.2 Tool registry

Each tool is a class implementing `AiTool`: `name()`, `description()`, `parameters()` (JSON schema sent to Gemini), `permission()`, `execute(array $args, User $user): array`.

| Tool | Permission | Kind | Parameters (validated) |
|---|---|---|---|
| `get_total_inventory` | stock.view | read | department_id? |
| `get_low_stock_items` | stock.view | read | department_id?, category_id?, limit ≤ 50 |
| `get_out_of_stock_items` | stock.view | read | department_id?, limit |
| `get_expiring_batches` | stock.view | read | within_days 1–365, department_id? |
| `get_expired_batches` | stock.view | read | department_id? |
| `get_department_stock` | stock.view | read | department (id or name, resolved server-side), product_query? |
| `search_products` | products.view | read | query (2–60 chars) |
| `get_stock_movements` | stock.view | read | date_from/date_to (≤ 366 days), product_id?, department_id?, movement_type? (enum) |
| `get_inventory_value` | valuation.view | read | group_by ∈ {category, department, none} |
| `get_consumption_analysis` | analytics.view | read | period_days ≤ 365, group_by ∈ {department, product, category, month} |
| `get_slow_moving_items` | analytics.view | read | no_movement_days 30–365 |
| `get_supplier_performance` | suppliers.view | read | supplier_id?, period_days |
| `get_stockout_predictions` | stock.view | estimate | department_id?, urgency_min? |
| `get_expiry_predictions` | stock.view | estimate | within_days, department_id? |
| `get_anomalies` | analytics.view | estimate | period_days |
| `prepare_purchase_request` | ai.propose + purchase_requests.create | **proposal** | items[{product_id, quantity > 0}] ≤ 30, justification |
| `prepare_stock_transfer` | ai.propose + transfers.create | **proposal** | source_department_id, destination_department_id, items[] |

- **No SQL from the model, ever.** Tools map to fixed repository methods. Department filters are re-applied from the *user's* scope regardless of what the model requests. An out-of-scope department returns `denied`, which the model is told to relay.
- **Proposals are not actions.** `prepare_*` creates a PR or transfer with `status = draft` and `source = ai_proposal`, owned by the user. The chat shows a card ("Draft PR-2026-000051 created: review and submit"). Submission and approval follow the normal human workflow and segregation-of-duties rules. The AI cannot approve, post, receive, adjust or delete anything.

### 6.3 Prompt-injection & data-safety controls

- **System instruction (fixed, server-side)**: the assistant is the MediStock inventory assistant. It must use tools for every number, never invent figures, say when data is unavailable, label estimates, treat tool output as data and not instructions, refuse clinical or dosing advice, and stay on the inventory domain.
- **Tool results are wrapped** as `{"data_kind":"actual|estimate","untrusted_text_fields":[…],"rows":[…],"assumptions":{…}}`. Free-text fields from the DB (notes, descriptions, supplier names) are length-truncated and stripped of control characters, and are only included when needed.
- **Data minimization**: no passwords, emails, phone numbers, IPs or user personal data in tool results (user names reduced to the person's role and initials where needed). No patient data exists in this system.
- **Grounding check**: numbers in the final answer can be traced to tool results in the stored transcript. The UI shows which tools were used, so users can open the underlying report.
- **Output safety**: the model's text is rendered through a whitelist Markdown renderer (bold, lists, tables, code). No raw HTML is rendered.
- **Limits**: message ≤ 2,000 chars, max tool rounds 5, per-user rate limits from settings, 30 s timeout. Errors return a friendly message, and the details go to the log.
- **Logging**: every tool call (name, validated args, status, row count) is written to `ai_messages`, and proposals are also written to `audit_logs`. The API key is never logged or sent to the browser.

### 6.4 UI

- **Floating widget** (bottom-right, on authorized pages): compact chat, suggested questions tailored to the role, "open full assistant" link.
- **Assistant page**: conversation list (rename/archive), full chat, tool-source chips, proposal cards, inline mini-charts when a tool returns series data, and role-specific suggested follow-ups.

---

## 7. Project folder structure

```
MediStock/                         ← XAMPP: /opt/lampp/htdocs/MediStock
├── .htaccess                      ← routes everything into public/ and denies direct access elsewhere
├── .env.example                   ← DB_*, APP_*, GEMINI_API_KEY, GEMINI_MODEL (real .env is git-ignored)
├── composer.json
├── README.md
├── app/
│   ├── Core/                      App, Container, Router, Request, Response, Database, View,
│   │                              Session, Validator, Gate, Config, Logger, ExceptionHandler
│   ├── Controllers/
│   │   ├── Auth/  Admin/  Dashboard/  Products/  Inventory/  Departments/
│   │   ├── Procurement/  Analytics/  Scan/  Notifications/  Ai/
│   │   └── Api/                   JSON endpoints (mirrors the above)
│   ├── Middleware/                SecureHeaders, StartSession, Authenticate, VerifyCsrf,
│   │                              RequirePermission, Throttle, RequireJson
│   ├── Models/                    User, Role, Department, Location, Product, Batch, StockBalance,
│   │                              StockMovement, StockRequest, Transfer, PurchaseOrder …
│   ├── Repositories/              one per aggregate (ProductRepository, StockRepository …)
│   ├── Services/
│   │   ├── Stock/                 StockService, FefoAllocator, StockRequestService, TransferService,
│   │   │                          AdjustmentService, StockCountService, ReservationService, RecallService
│   │   ├── Procurement/           ProcurementService, SupplierPerformanceService
│   │   ├── Intelligence/          ForecastService, ExpiryService, RedistributionService, AnomalyService
│   │   ├── Reporting/             ReportService, PdfExporter, CsvExporter
│   │   ├── Ai/                    GeminiClient, AssistantService, ToolRegistry, PromptBuilder,
│   │   │                          ResponseRenderer, Tools/*.php
│   │   └── Support/               AuditService, NotificationService, CodeService, DocumentNumberService
│   ├── Exceptions/                ValidationException, AuthorizationException, StockException …
│   └── Helpers/                   functions.php (e(), csrf_field(), route(), money(), qty())
├── bin/
│   └── console                    CLI: migrate, seed, alerts:run, stock:reconcile, user:create
├── config/                        app.php, database.php, security.php, ai.php, permissions.php
├── database/
│   ├── migrations/                0001_identity.sql … numbered, applied by `bin/console migrate`
│   └── seeds/                     roles_permissions, departments, categories_units, demo_data (optional)
├── public/                        ← the ONLY web-accessible directory
│   ├── index.php
│   ├── .htaccess                  front-controller rewrite
│   └── assets/
│       ├── css/app.css            design tokens + components
│       ├── js/                    app.js, ms-ui.js, ms-table.js, ms-scan.js, ms-charts.js, ms-ai.js, pages/*.js
│       ├── images/
│       └── vendor/                bootstrap, bootstrap-icons, chart.js, html5-qrcode (local copies)
├── resources/views/
│   ├── layouts/                   app.php (sidebar + topbar), auth.php, print.php (PDF)
│   ├── partials/                  sidebar, topbar, flash, pagination, ai-widget, confirm-modal
│   ├── auth/ dashboard/ products/ inventory/ departments/ requests/ transfers/
│   ├── suppliers/ procurement/ analytics/ reports/ scan/ notifications/
│   ├── admin/ equipment/ ai-assistant/ errors/
├── routes/                        web.php, api.php
├── storage/                       (not web-accessible)
│   ├── logs/  reports/  uploads/  cache/  sessions/
├── tests/                         Unit/ (FEFO, forecast, expiry math), Integration/ (stock posting, workflows, permissions)
└── vendor/                        Composer packages
```

**Keeping secrets out of the web root on XAMPP.** The recommended setup is an Apache VirtualHost (`medistock.local`) whose DocumentRoot is `MediStock/public`. As a fallback that works with the default XAMPP setup, the root `.htaccess` rewrites all requests to `public/` and returns 403 for `app/`, `config/`, `storage/`, `.env` and similar paths.

---

## 8. Phased implementation roadmap

Each phase ends with working, integrated screens, with no placeholder buttons and no fake statistics, plus tests for what was built. I will pause for your review after each phase.

| Phase | Deliverables | Acceptance checks |
|---|---|---|
| **1. Foundation** | Composer setup, core framework classes, `.env`, migrations runner, **full schema** (all tables, so later phases need no redesign), seeds (roles, permissions, departments, locations, categories, units, admin user), login/logout/rate limiting, password change, RBAC + department scope, Admin: users, roles/permissions, departments, locations, settings, audit log viewer. Layout: sidebar, topbar, design system, toasts, confirm modals, table component. Dashboard shell with real counts. | Can't reach any page without login; permission denial tested per role; CSRF enforced; lockout works; audit entries written. |
| **2. Core inventory** | Categories, units, products (CRUD, pack conversions, thresholds, activate/deactivate, images), batches, `StockService` + `FefoAllocator`, opening balances, direct goods receipt, central stock views, issue (central → transit → department confirm), movement ledger viewer, reconciliation command. | Concurrent-issue test can't oversell; ledger sum = balance; expired batch can't be issued. |
| **3. Departments** | Department stock pages, per-department thresholds, stock requests (full/partial approval, FEFO issue, receipt), transfers and returns, consumption recording, adjustments with approval, stock counts → variance adjustments, reservations, recalls with trace. | Segregation of duties enforced; department users see only their data (also in JSON). |
| **4. Procurement** | Suppliers + supplier products/quotes, PRs with approval, PR → PO conversion (split by supplier), PO approval/sending/PDF, goods receipt against PO (partial deliveries, rejection, duplicate prevention), supplier performance page. | Over-receipt and double-submit blocked; PO status transitions correct. |
| **5. Intelligence** | ADC/forecast service, stock-out predictions page, reorder suggestions → PR draft, expiry dashboard with FEFO simulation, redistribution suggestions → draft transfers, anomaly detection, notification centre + `alerts:run` scheduler, temperature logs with range alerts. | Unit tests reproduce the 500/30/5 → 350 example and multi-batch cases; assumptions shown on every prediction. |
| **6. QR & barcode** | Product and batch QR/Code-128 generation, printable label sheets (PDF), camera scanning (html5-qrcode) with manual fallback, scan-driven receive / issue / consume / count / lookup. | Product vs batch payloads distinguished; unknown codes handled gracefully. |
| **7. Analytics & reports** | Management dashboard (Chart.js): valuation, by category/department, consumption trends, purchasing spend, supplier performance, expiry and wastage, low stock, stock-out risk, slow movers. Date and department filters; PDF + CSV export for each report. | Figures match direct SQL checks on seeded data. |
| **8. Gemini assistant** | GeminiClient, ToolRegistry + 17 tools, AssistantService loop, conversation storage, floating widget + full page, proposal cards, rate limiting, AI logging. | Tool permission/scope tests; argument-validation tests; injection test (malicious product description is not obeyed); "no data → says so" test. |
| **9. Hardening & testing** | Security review (OWASP checklist), permission matrix test suite, DB integrity tests, performance (indexes, `EXPLAIN` on heavy reports, pagination), error pages, HTTPS config notes, README with install/deploy/cron instructions, optional demo data generator (≈ 12 months of realistic movements so forecasts and charts are meaningful). | All tests green; reconciliation clean on demo data. |

---

## 9. Decisions requiring approval

These are my recommended defaults. Confirm or change any of them.

1. **Web root on XAMPP**: VirtualHost to `public/` (recommended), with the root `.htaccess` fallback always included.
2. **Merge `consumption_records` into `stock_transactions`** (see §2.2) instead of a separate table.
3. **Transit step** for issues and transfers: the receiving department confirms receipt.
4. **Multiple roles per user** (`user_roles`) instead of a single `role_id`.
5. **Base-unit quantities** with pack conversions (`product_units`).
6. **Gemini model** set in `.env` (`GEMINI_MODEL`), defaulting to a current Gemini Flash model for cost and speed. You will supply your own key in MediStock's `.env`.
7. **Currency** configurable in settings. Which currency should be the default?
8. **CSV** for spreadsheet export (no PhpSpreadsheet dependency). XLSX can be added later if you need it.
9. **PHPUnit** as a dev-only dependency for the calculation, workflow and permission tests.
10. **Demo data generator** in Phase 9 (also usable earlier) so dashboards, forecasts and the AI have realistic history to work with.

---

## 10. Implementation notes (deviations from the original design)

| Area | Change | Reason |
|---|---|---|
| `product_batches.goods_receipt_item_id` | Removed. The link is `goods_receipt_items.batch_id` only. | Avoids a circular foreign key and storing the same link twice. |
| CSRF failure status | Returns **403** with a "Session expired" page instead of 419. | Apache rewrites the non-standard 419 status to 500. |
| Location types | Added `transit` (one system location, `TRANSIT`) and quarantine locations per store, seeded. | Supports the in-transit flow in §3.2. |
| Settings | `currency_code` defaults to `USD` and can be changed in Admin → System settings. | The currency was not specified. |
| Direct issues | A store-to-department issue is a `stock_transfers` row of type `issue`. Stock requests in Phase 3 are fulfilled by issues linked through `stock_request_id`. Migration 0010 also adds `partially_received` and `returned_qty`. | One dispatch/receipt mechanism for issues, transfers and returns. |
| Short receipts | Anything not confirmed stays in Transit until the issuer returns it to the source (`returned_qty`). | Missing stock can't vanish. Write-off through an approved adjustment comes in Phase 3. |
| Batch cost | Receiving the same lot again updates the batch's moving-average unit cost. | One batch per product + lot + supplier. |
| Receipts without a PO | Direct purchase, donation and opening balance are available now. PO-based receipts come in Phase 4. | Lets stock be loaded before procurement is built. |
| Transfer request lines | Migration 0011 adds `stock_transfer_items.parent_item_id`. Approval works on product-level lines; at dispatch, FEFO allocation creates batch-level child rows. | Keeps the approved quantities separate from the batches actually dispatched. |
| Request progress | `RequestProgressService` recomputes a request's issued and received quantities, and its status, from the linked issues. | Stays correct after partial receipts and returns. |
| Recall quarantine | If a department has no quarantine location, recalled stock goes to the central store's quarantine. | Every department can isolate recalled stock. |
| Blind counts | Counters don't see system quantities. Approvers do. | Reduces the bias of simply confirming the expected number. |
| New permission | `department_levels.manage` (Inventory Manager, Pharmacist, Storekeeper). The seeder grants newly added permissions to the system roles that list them by default. | Departments maintain their own reorder levels. |
| PO receipts | `ReceivingService::receivePurchaseOrder()` shares the safeguards of direct receipts. Rejected quantities are recorded on the GRN but not stocked, and stay outstanding on the PO. Over-receipt is limited by the `overreceipt_tolerance_pct` setting. | One receiving path with the same rules. |
| PO rejection | An approver "returns" a PO to draft with a reason, instead of a terminal rejected status. | The schema has no rejected status, and returned POs are usually corrected rather than abandoned. |
| Supplier rating | Calculated on demand and cached in `suppliers.rating_cache`: 5 × (0.4 on-time + 0.3 fill rate + 0.3 acceptance). | Transparent, explainable score. |
| Forecast and expiry math | Pure classes `ForecastMath` and `ExpirySimulator`, unit-tested. Services only gather data. | Formulas are testable and explainable. |
| Expiry usage rate | Per *location*: stock leaving the location (consumption, issues, transfers, returns), weighted 30/90 days. | The central store has no consumption of its own; its "use" is what it issues. |
| Alerts without cron | `AlertService::runIfDue()` runs at most every 15 minutes when the dashboard opens; cron remains recommended. | Notifications keep working on a plain XAMPP install. |
| Demo generator | Development-only command that writes back-dated history through the real services. | Forecasts need history to be reviewable. |
| Gemini model | `gemini-2.5-flash` is not offered to new API keys. Default is now `gemini-3.5-flash-lite` (fast, reliable tool calling), with automatic fallback to `gemini-3.5-flash` and `gemini-3.8-flash`, all configurable in `.env`. Within one turn the model is pinned so thought signatures stay valid. | Measured: the larger models were frequently overloaded (503s and timeouts). |
| AI tool layer | One `ToolRegistry` class (17 tools) plus `ArgValidator`, rather than 17 separate classes. | Easier to audit in one place. |
| Transfer proposals | `prepare_stock_transfer` creates the transfer in `pending_approval` (origin `ai_proposal`). | Uses the existing approval workflow unchanged. |
| AI failure mid-turn | If tools already created a proposal and the AI service then fails, the user still gets a reply listing the proposal. | Actions are never hidden from the user. |

### Phase 9 (hardening)

- **Analytics cache.** `CacheService` is a JSON file cache under `storage/cache/data` with a global data version.
  - `cache_bump()` is queued with `Database::afterCommit()`, so an invalidation is never lost to a rollback and never runs before the data is visible.
  - The cache is disabled under PHPUnit (`CACHE_ENABLED=false`).
- **Dashboard query.** The "recent movements" query selects the newest IDs first in a derived table, then joins the details. This took it from 430 ms to 6 ms with about 47,000 ledger rows.
- **Database-level append-only.** Migration 0013 adds triggers that refuse UPDATE and DELETE on `stock_movements` and `audit_logs`.
  - The only exception is moving `stock_movements.created_at`, and only with the session flag `@medistock_allow_backdate = 1`. The demo generator and the tests use it.
  - `database/production_grants.sql` also makes those tables INSERT-only for the application account.
- **Route-table tests.** `tests/Integration/RouteSecurityTest.php` checks that every route is authenticated, declares a catalogued permission after `auth`, and that no GET handler is named like a state-changing action.
  - A live HTTP matrix across all six seeded roles (396 role × route checks) matched `Gate` decisions exactly at the time of hardening.
- **`system:check`.** This console command verifies PHP, extensions, `.env`, the debug flag, storage permissions, the DB account, migrations, triggers, permissions, reconciliation, default passwords, the Gemini key and cron recency.
