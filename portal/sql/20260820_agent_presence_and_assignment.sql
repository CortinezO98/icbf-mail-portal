-- R1/R2 - Presencia de agentes y base operacional para asignacion automatica
-- MariaDB 10.11+
-- Ejecutar antes de habilitar ASSIGNMENT_WORKER_ENABLED=1.


-- Índice dedicado a la bandeja principal / assignment worker.
-- El sistema ya tiene otros índices de casos, pero este cubre exactamente
-- status + sin asignar + FIFO por received_at/id.
ALTER TABLE cases
    ADD KEY idx_cases_assignment_queue
        (status_id, assigned_user_id, received_at, id);

CREATE TABLE agent_presence_statuses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    color_hex VARCHAR(20) DEFAULT NULL,
    is_assignable TINYINT(1) NOT NULL DEFAULT 0,
    is_selectable TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_presence_status_code (code),
    KEY idx_agent_presence_status_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO agent_presence_statuses
    (code, name, color_hex, is_assignable, is_selectable, sort_order, is_active)
VALUES
    ('DISPONIBLE',      'Disponible',       '#22c55e', 1, 1, 10, 1),
    ('EN_LINEA_NO_ACD', 'En línea No ACD',  '#93c5fd', 0, 1, 20, 1),
    ('ALMORZANDO',      'Almorzando',       '#fbbf24', 0, 1, 30, 1),
    ('AUSENTE',         'Ausente',          '#fbbf24', 0, 1, 40, 1),
    ('BANO',            'Baño',             '#fbbf24', 0, 1, 50, 1),
    ('CAPACITACION',    'Capacitación',     '#fbbf24', 0, 1, 60, 1),
    ('DESCONECTADO',    'Desconectado',     '#94a3b8', 0, 0, 90, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    color_hex = VALUES(color_hex),
    is_assignable = VALUES(is_assignable),
    is_selectable = VALUES(is_selectable),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active);

CREATE TABLE agent_presence (
    user_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    status_since DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    KEY idx_agent_presence_status (status_id),
    KEY idx_agent_presence_last_seen (last_seen_at),
    CONSTRAINT fk_agent_presence_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_agent_presence_status
        FOREIGN KEY (status_id) REFERENCES agent_presence_statuses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE agent_presence_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_at DATETIME(6) DEFAULT NULL,
    changed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'PORTAL',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_presence_history_user_started (user_id, started_at),
    KEY idx_presence_history_open (user_id, ended_at),
    KEY idx_presence_history_status (status_id),
    CONSTRAINT fk_presence_history_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_presence_history_status
        FOREIGN KEY (status_id) REFERENCES agent_presence_statuses(id),
    CONSTRAINT fk_presence_history_changed_by
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado inicial seguro: ningún agente se considera disponible por una migración.
-- El siguiente login lo moverá a EN_LINEA_NO_ACD y el agente deberá elegir Disponible.
INSERT INTO agent_presence (user_id, status_id, status_since, last_seen_at, updated_at)
SELECT DISTINCT
    u.id,
    s.id,
    NOW(6),
    NOW(6),
    NOW(6)
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
JOIN roles r ON r.id = ur.role_id
JOIN agent_presence_statuses s ON s.code = 'DESCONECTADO'
WHERE UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);
