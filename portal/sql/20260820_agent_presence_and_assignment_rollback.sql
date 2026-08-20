-- Rollback de esquema R1/R2.
-- Solo ejecutar DESPUÉS de revertir/deshabilitar portal y assignment worker.
DROP TABLE IF EXISTS agent_presence_history;
DROP TABLE IF EXISTS agent_presence;
DROP TABLE IF EXISTS agent_presence_statuses;

ALTER TABLE cases DROP INDEX IF EXISTS idx_cases_assignment_queue;
