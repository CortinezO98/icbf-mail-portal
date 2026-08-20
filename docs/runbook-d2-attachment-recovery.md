# Runbook D2 - Recuperación de adjuntos por identidad Graph

## Objetivo

D2 reemplaza el recovery histórico basado en `COUNT(*) == 0` por un proceso persistente que compara la identidad real de Graph:

`expected_graph_ids - persisted_graph_ids = missing_ids`

Esto permite recuperar 0/N, 1/N, 2/N, etc., evita falsos `recovered`, protege crashes intermedios y estabiliza el manifiesto antes de declarar `complete`.

## Estados

- `pending`: falta trabajo o Graph no es verificable todavía.
- `verifying`: N/N en una lectura; necesita otra lectura estable.
- `complete`: mismo manifiesto estable, N/N y fuera de la ventana de estabilización.
- `blocked`: causa conocida no recuperable automáticamente (por ejemplo política, tipo no soportado o identidad legacy desconocida).

`locked_at` es ownership/concurrencia, no un estado lógico. Locks viejos (> `ATTACHMENT_RECOVERY_STALE_LOCK_MINUTES`) pueden reclamarse.

## Configuración

```env
ATTACHMENT_RECOVERY_ENABLED=1
ATTACHMENT_RECOVERY_POLL_SECONDS=30
ATTACHMENT_RECOVERY_VERIFICATION_DELAY_SECONDS=120
ATTACHMENT_RECOVERY_STALE_LOCK_MINUTES=10
ATTACHMENT_RECOVERY_LONG_TAIL_SECONDS=21600
ATTACHMENT_RECOVERY_BATCH_SIZE=50
```

Se reutiliza `ATTACHMENTS_STABILIZATION_WINDOW_MINUTES=15`.

## Rollout producción

1. Tomar checkpoint/backup y confirmar rama/commit.
2. Crear únicamente la tabla con `worker/sql/d2_attachment_recovery_schema.sql`.
3. Verificar `SHOW CREATE TABLE attachment_recovery\G` y FK.
4. Desplegar código D2.
5. Reiniciar solamente el worker correspondiente.
6. Verificar `systemctl status` y logs de arranque.
7. Observar al menos varios ciclos del poll sin backfill.
8. Verificar que no aparecen errores SQL de tabla/constraint, loops de excepción, ni locks permanentes anómalos.
9. Re-ejecutar `worker/sql/d2_backfill_preview.sql`.
10. Si los conteos son los autorizados en ese momento, ejecutar `worker/sql/d2_backfill.sql`.
11. Observar transición `pending -> verifying -> complete|blocked`.

## Smoke checks

```sql
SELECT status, COUNT(*)
FROM attachment_recovery
GROUP BY status;

SELECT message_id, status, attempts, expected_count, downloaded_count,
       last_reason, available_at, locked_at, updated_at
FROM attachment_recovery
WHERE status IN ('pending','verifying','blocked')
ORDER BY updated_at DESC
LIMIT 100;

SELECT COUNT(*) AS stale_locked
FROM attachment_recovery
WHERE status IN ('pending','verifying')
  AND locked_at IS NOT NULL
  AND locked_at < NOW(6) - INTERVAL 10 MINUTE;
```

## Legacy

Los mensajes que ya poseen cualquier fila `attachments.graph_attachment_id IS NULL` se bloquean con `LEGACY_ATTACHMENT_IDENTITY_UNKNOWN`; D2 no intenta redescargarlos automáticamente. El preflight histórico detectó 1.878 mensajes en esta categoría. No hacer backfill ciego de IDs.

## Rollback

No hacer `DROP TABLE` primero.

1. Detener/revertir el código y wiring D2.
2. Reiniciar el worker.
3. Confirmar funcionamiento normal y que ya no hay callers runtime de `attachment_recovery`.
4. Conservar la tabla temporalmente para diagnóstico.
5. Solo si se decide explícitamente eliminar el estado D2, ejecutar `worker/sql/d2_rollback.sql`.

La tabla es operacional y tiene FK saliente hacia `messages`; revertir código no requiere revertir `messages` ni `attachments`.
