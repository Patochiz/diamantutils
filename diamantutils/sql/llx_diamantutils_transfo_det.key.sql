-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_transfo_det
-- ============================================================================

ALTER TABLE llx_diamantutils_transfo_det ADD INDEX idx_diamantutils_transfo_det_fk_transfo (fk_transfo);
ALTER TABLE llx_diamantutils_transfo_det ADD INDEX idx_diamantutils_transfo_det_fk_product (fk_product);
