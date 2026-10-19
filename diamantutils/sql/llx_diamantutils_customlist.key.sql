-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_customlist
-- ============================================================================

ALTER TABLE llx_diamantutils_customlist ADD INDEX idx_diamantutils_customlist_entity (entity, active, position);
