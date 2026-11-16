-- =====================================================================
-- 0012 Performance: covering indexes for consumption/outflow analytics.
-- Forecasts, expiry risk and reports aggregate movements by type, location
-- and date; these indexes let MySQL answer from the index alone.
-- =====================================================================

ALTER TABLE stock_movements
    ADD KEY ix_mov_type_from_date (movement_type, from_location_id, created_at, product_id, quantity, unit_cost),
    ADD KEY ix_mov_from_date_type (from_location_id, created_at, movement_type, product_id, quantity);

ALTER TABLE stock_balances
    ADD KEY ix_bal_qty_location (quantity, location_id, batch_id, product_id);

ALTER TABLE notifications
    ADD KEY ix_notifications_created (created_at);
