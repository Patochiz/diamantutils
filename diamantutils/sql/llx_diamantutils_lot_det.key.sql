-- ============================================================================
-- DiamantUtils — Clés de llx_diamantutils_lot_det
-- ============================================================================

ALTER TABLE llx_diamantutils_lot_det ADD INDEX idx_diamantutils_lot_det_fk_lot (fk_lot, rang);
ALTER TABLE llx_diamantutils_lot_det ADD INDEX idx_diamantutils_lot_det_fk_product (fk_product);
-- Suppression d'un lot : ses lignes sont supprimées avec lui
ALTER TABLE llx_diamantutils_lot_det ADD CONSTRAINT fk_diamantutils_lot_det_fk_lot FOREIGN KEY (fk_lot) REFERENCES llx_diamantutils_lot (rowid) ON DELETE CASCADE;
