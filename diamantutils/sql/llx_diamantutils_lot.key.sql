-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_lot
-- ============================================================================

ALTER TABLE llx_diamantutils_lot ADD INDEX idx_diamantutils_lot_entity (entity, active);
