-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_stockline
-- Pas d'unicité sur (produit, lot) : un même lot peut occuper plusieurs lignes.
-- ============================================================================

ALTER TABLE llx_diamantutils_stockline ADD INDEX idx_diamantutils_stockline_product (entity, fk_product, batch);
