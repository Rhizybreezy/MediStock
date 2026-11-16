-- =====================================================================
-- 0011 Department workflows
-- Transfer/return REQUEST lines are product-level (batch_id NULL). When the
-- transfer is dispatched, FEFO allocation creates batch-level rows that point
-- back to their request line through parent_item_id.
-- =====================================================================

ALTER TABLE stock_transfer_items
    ADD COLUMN parent_item_id BIGINT UNSIGNED NULL AFTER stock_transfer_id,
    ADD KEY ix_sti_parent (parent_item_id),
    ADD CONSTRAINT fk_sti_parent FOREIGN KEY (parent_item_id) REFERENCES stock_transfer_items (id) ON DELETE CASCADE;

-- Consumption may optionally record a purpose/category (never patient-identifiable data).
ALTER TABLE stock_transactions
    ADD COLUMN purpose VARCHAR(60) NULL AFTER reason;
