-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_transfo
-- ============================================================================

ALTER TABLE llx_diamantutils_transfo ADD UNIQUE INDEX uk_diamantutils_transfo_ref (ref, entity);
ALTER TABLE llx_diamantutils_transfo ADD INDEX idx_diamantutils_transfo_fk_warehouse (fk_warehouse);
ALTER TABLE llx_diamantutils_transfo ADD INDEX idx_diamantutils_transfo_fk_commande (fk_commande);
