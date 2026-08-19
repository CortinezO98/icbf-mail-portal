# Tests — worker/app

## Cómo correrlos

```bash
cd worker
pip install -r requirements-dev.txt
PYTHONPATH=. pytest
```

Con cobertura sobre los módulos tocados hasta ahora:

```bash
PYTHONPATH=. pytest --cov=app.sync_service --cov=app.graph_client \
  --cov=app.inbound_queue_worker --cov=app.webhook --cov=app.delta_routes \
  --cov-report=term-missing
```

Estado actual: **101 tests, todos unitarios** (sin DB ni red reales).

## Alcance por fase (SWEBOK v4 — Software Testing KA: niveles de prueba)

### Fase 1 — C1 (cierre de las tres puertas de materialización paralelas)
- `test_webhook.py` (22 tests, 100% cobertura del módulo) — se reescribió
  completo al eliminar la cola en memoria.
- `test_delta_routes.py` (14 tests, 69% cobertura — intencional, solo
  `/admin/reprocess` y `/admin/reprocess-batch` cambiaron).
- `test_inbound_queue_worker.py` (19 tests, 92% cobertura) — incluye el
  fix de `_TERMINAL_BY_DESIGN_STATUSES` (status terminal-por-diseño no
  debe consumir presupuesto de reintentos) y el refactor de `_process_one`
  a nivel de módulo para poder testearlo aislado.

### Fase 2 — Completeness Gate (C4/C5/C6/A1/A2/A5)
- `test_sync_service_completeness_gate.py` (16 tests) — tabla de decisión
  completa de `_evaluate_completeness` (body: ausente/sin content
  key/content null/content vacío-válido/con contenido; adjuntos:
  hasAttachments false/manifiesto vacío/manifiesto poblado) y de
  `_incomplete_or_degrade` (retry vs degradar, casos límite de
  `attempts`/`max_attempts`).
- `test_sync_service_process_single_message.py` (13 tests) — flujo
  completo con mocks: los 3 motivos de incompletitud cruzados con
  presupuesto agotado/no agotado, y las guardas de que el gate **no**
  corre cuando no debe (mensaje ya materializado, filtros
  `GO_LIVE_AT`/`STOP_NEW_INBOUND_AT` existentes). Incluye el test de
  orden de evaluación: si falta `receivedDateTime`, los filtros de fecha
  no se aplican (no hay fecha con la que comparar).
- `test_sync_service_process_attachments.py` (8 tests) — A5: los cuatro
  motivos de fallo por adjunto (sin contentBytes, base64 inválido,
  rechazado por política, sin sha256) quedan en un evento estructurado
  `ATTACHMENTS_PARTIAL_FAILURE` en vez de perderse en un `continue`
  silencioso. Cubre también el reuso del manifiesto ya obtenido por el
  gate (no se vuelve a llamar a Graph).
- `test_graph_client.py` (10 tests) — paginación de `list_attachments`
  siguiendo `@odata.nextLink`, y ciclo de vida del cliente `httpx`
  persistente.

**Todos los tests que fijan un comportamiento de bug-fix se validaron con
prueba de mutación**: se revirtió el fix correspondiente temporalmente
(la rama `_TERMINAL_BY_DESIGN_STATUSES`, y la lógica de
`_incomplete_or_degrade` para no degradar nunca) y se confirmó que
fallaban exactamente los tests esperados, ni más ni menos.

## Qué NO está cubierto todavía (fuera de alcance, no negado)

- **Funciones de acceso a datos crudo** (`_get_existing_message_row`,
  `_touch_case_activity`, `_attachments_count`, `_message_exists`,
  `_find_last_case_by_conversation`) — son consultas SQL puras. En los
  tests del gate se mockean completas (correcto para un test unitario);
  probarlas de verdad requiere una base de datos real — nivel de
  integración, pendiente de Fase de Docker/DB de pruebas.
- **`recover_missing_attachments`** (el job de recuperación sin límite de
  intentos) — implementado, sin tests dedicados todavía. Candidato
  inmediato para la siguiente pasada.
- **`_notify_agent_new_case`** y **`process_notifications_async`** (el
  segundo es un entrypoint que ya no usa ningún camino activo del
  sistema — webhook, delta y reconcile pasan por
  `inbound_queue_repo.enqueue_event`, no por aquí) — sin cambios de
  código en esta fase, sin tests nuevos.
- **`graph_client.py`**: `get_message`, `send_mail`,
  `create_subscription`, `renew_subscription`, `messages_delta_page` —
  sin cambios de código en esta fase (solo `list_attachments` y el
  cliente persistente cambiaron), sin tests nuevos.

## Hallazgo colateral durante el testing (no bloqueante, anotado para más adelante)

Al escribir el test de "base64 inválido" en `_process_attachments`,
confirmé que `base64.b64decode(...)` en modo no estricto (el que usa el
código, sin `validate=True`) es permisivo: para la mayoría de strings
"basura" con longitud múltiplo de 4 no lanza excepción, simplemente
decodifica bytes sin sentido. Solo dispara error con padding
inconsistente (longitud no múltiplo de 4). Esto significa que la
detección actual de "base64 inválido" es menos confiable de lo que el
código sugiere — un adjunto corrupto con longitud "casualmente válida"
pasaría como bytes basura en vez de ser detectado y reportado como
`INVALID_BASE64`. No lo corregí en esta fase (no estaba en el alcance
acordado), pero queda anotado como candidato a revisar — posiblemente
agregando una validación de tipo de archivo/magic bytes después de
decodificar, no solo confiar en que decodificar sin excepción signifique
"contenido válido".
