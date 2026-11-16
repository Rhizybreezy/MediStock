-- =====================================================================
-- 0010 Unified dispatch/receipt flow
-- A direct issue from a store to a department is a stock_transfer of type
-- 'issue'. Stock requests (Phase 3) are fulfilled by issue transfers linked
-- through stock_request_id. Shortfalls found at receipt can be returned to the
-- source location (returned_qty) instead of silently disappearing.
-- =====================================================================

ALTER TABLE stock_transfers
    MODIFY type ENUM('issue','transfer','return_to_store','redistribution') NOT NULL DEFAULT 'transfer',
    MODIFY source ENUM('manual','stock_request','ai_proposal','redistribution_engine') NOT NULL DEFAULT 'manual',
    MODIFY status ENUM('draft','pending_approval','approved','rejected','dispatched','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN stock_request_id BIGINT UNSIGNED NULL AFTER source,
    ADD COLUMN discrepancy_notes VARCHAR(255) NULL AFTER rejection_reason,
    ADD KEY ix_trf_request (stock_request_id),
    ADD CONSTRAINT fk_trf_request FOREIGN KEY (stock_request_id) REFERENCES stock_requests (id) ON DELETE RESTRICT;

ALTER TABLE stock_transfer_items
    ADD COLUMN returned_qty DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER received_qty,
    DROP CONSTRAINT ck_sti_qty,
    ADD CONSTRAINT ck_sti_qty CHECK (requested_qty > 0 AND dispatched_qty >= 0 AND received_qty >= 0
                                     AND returned_qty >= 0 AND received_qty + returned_qty <= dispatched_qty);
