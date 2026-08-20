-- D2 schema cleanup - NOT the first rollback step.
-- Correct operational rollback order:
--   1) revert D2 code/wiring,
--   2) restart the worker and verify healthy operation,
--   3) confirm zero runtime callers depend on attachment_recovery,
--   4) preferably keep this table temporarily for diagnosis,
--   5) only then, if explicitly desired, run the DROP below.

DROP TABLE IF EXISTS attachment_recovery;
