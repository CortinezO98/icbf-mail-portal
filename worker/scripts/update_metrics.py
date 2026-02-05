# File: worker/scripts/update_metrics.py (VERSIÓN ACTUALIZADA)
#!/usr/bin/env python3
"""
Worker para actualizar métricas del semáforo (0-5 días)
Se ejecuta cada hora via cron
"""

import mysql.connector
import logging
from datetime import datetime, timedelta
from typing import Dict, List, Any

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class SemaforoWorker:
    def __init__(self):
        self.db_config = {
            'host': '127.0.0.1',
            'port': 3306,
            'database': 'icbf_mail',
            'user': 'root',
            'password': '',
            'charset': 'utf8mb4'
        }
        self.connection = None
        
    def connect(self):
        """Conectar a MySQL"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            logger.info("Conectado a la base de datos")
            return True
        except Exception as e:
            logger.error(f"Error conectando a MySQL: {e}")
            return False
    
    def update_sla_tracking(self) -> Dict[str, int]:
        """Actualizar tracking SLA basado en días desde creación"""
        cursor = self.connection.cursor(dictionary=True)
        
        try:
            # 1. Inicializar casos nuevos
            init_query = """
                INSERT IGNORE INTO case_sla_tracking (case_id, days_since_creation, current_sla_state)
                SELECT 
                    c.id,
                    DATEDIFF(NOW(), c.created_at),
                    CASE
                        WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                        WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                        ELSE 'ROJO'
                    END
                FROM cases c
                WHERE c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
                AND NOT EXISTS (
                    SELECT 1 FROM case_sla_tracking cst 
                    WHERE cst.case_id = c.id
                )
            """
            cursor.execute(init_query)
            initialized = cursor.rowcount
            
            # 2. Actualizar tracking existente
            update_query = """
                UPDATE case_sla_tracking cst
                JOIN cases c ON c.id = cst.case_id
                SET
                    cst.days_since_creation = DATEDIFF(NOW(), c.created_at),
                    cst.current_sla_state =
                        CASE
                            WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 'VERDE'
                            WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 'AMARILLO'
                            ELSE 'ROJO'
                        END,
                    cst.last_updated = NOW()
                WHERE c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
            """
            cursor.execute(update_query)
            updated = cursor.rowcount
            
            # 3. Marcar como respondidos los casos que ya tienen respuesta
            respondidos_query = """
                UPDATE case_sla_tracking cst
                JOIN cases c ON c.id = cst.case_id
                SET cst.current_sla_state = 'RESPONDIDO'
                WHERE c.is_responded = 1
                AND cst.current_sla_state != 'RESPONDIDO'
            """
            cursor.execute(respondidos_query)
            responded = cursor.rowcount
            
            self.connection.commit()
            
            logger.info(f"SLA Tracking: {initialized} inicializados, {updated} actualizados, {responded} marcados como respondidos")
            
            return {
                'initialized': initialized,
                'updated': updated,
                'responded': responded
            }
            
        except Exception as e:
            logger.error(f"Error actualizando SLA tracking: {e}")
            self.connection.rollback()
            return {}
        finally:
            cursor.close()
    
    def calculate_semaforo_metrics(self) -> Dict[str, Any]:
        """Calcular métricas del semáforo"""
        cursor = self.connection.cursor(dictionary=True)
        
        try:
            # Distribución del semáforo
            query = """
                SELECT
                    -- VERDE (0-1 días)
                    SUM(CASE 
                        WHEN c.is_responded = 0
                        AND DATEDIFF(NOW(), c.created_at) <= 1
                        THEN 1 ELSE 0 
                    END) as verde,
                    
                    -- AMARILLO (2-3 días)
                    SUM(CASE 
                        WHEN c.is_responded = 0
                        AND DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3
                        THEN 1 ELSE 0 
                    END) as amarillo,
                    
                    -- ROJO (4+ días)
                    SUM(CASE 
                        WHEN c.is_responded = 0
                        AND DATEDIFF(NOW(), c.created_at) >= 4
                        THEN 1 ELSE 0 
                    END) as rojo,
                    
                    -- RESPONDIDOS
                    SUM(CASE WHEN c.is_responded = 1 THEN 1 ELSE 0 END) as respondidos,
                    
                    -- TOTAL ABIERTOS
                    COUNT(*) as total_abiertos
                FROM cases c
                WHERE c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
            """
            cursor.execute(query)
            semaforo = cursor.fetchone()
            
            # Casos críticos (ROJO) detallados
            query = """
                SELECT
                    c.id,
                    c.case_number,
                    c.subject,
                    c.requester_email,
                    u.full_name as assigned_to,
                    DATEDIFF(NOW(), c.created_at) as dias_desde_creacion,
                    TIMESTAMPDIFF(HOUR, c.created_at, NOW()) as horas_desde_creacion
                FROM cases c
                LEFT JOIN users u ON u.id = c.assigned_user_id
                WHERE c.is_responded = 0
                AND DATEDIFF(NOW(), c.created_at) >= 4
                AND c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
                ORDER BY c.created_at ASC
                LIMIT 20
            """
            cursor.execute(query)
            criticos = cursor.fetchall()
            
            # Por agente
            query = """
                SELECT
                    u.id as agent_id,
                    u.full_name,
                    COUNT(*) as total_casos,
                    SUM(CASE 
                        WHEN DATEDIFF(NOW(), c.created_at) <= 1 THEN 1 ELSE 0 
                    END) as verde,
                    SUM(CASE 
                        WHEN DATEDIFF(NOW(), c.created_at) BETWEEN 2 AND 3 THEN 1 ELSE 0 
                    END) as amarillo,
                    SUM(CASE 
                        WHEN DATEDIFF(NOW(), c.created_at) >= 4 THEN 1 ELSE 0 
                    END) as rojo
                FROM cases c
                JOIN users u ON u.id = c.assigned_user_id
                WHERE u.is_active = 1
                AND c.is_responded = 0
                AND c.status_id != (SELECT id FROM case_statuses WHERE code = 'CERRADO')
                GROUP BY u.id, u.full_name
                ORDER BY rojo DESC, amarillo DESC
                LIMIT 10
            """
            cursor.execute(query)
            agentes = cursor.fetchall()
            
            return {
                'semaforo': semaforo or {},
                'criticos': criticos,
                'agentes': agentes,
                'calculated_at': datetime.now().isoformat()
            }
            
        except Exception as e:
            logger.error(f"Error calculando métricas: {e}")
            return {}
        finally:
            cursor.close()
    
    def update_performance_cache(self, metrics: Dict[str, Any]) -> bool:
        """Actualizar cache de rendimiento"""
        cursor = self.connection.cursor()
        
        try:
            today = datetime.now().date()
            semaforo = metrics.get('semaforo', {})
            
            # Insertar métricas diarias
            cursor.execute("""
                INSERT INTO performance_metrics 
                (metric_date, metric_type, data_key, data_value)
                VALUES 
                (%s, 'daily', 'verde', %s),
                (%s, 'daily', 'amarillo', %s),
                (%s, 'daily', 'rojo', %s),
                (%s, 'daily', 'respondidos', %s),
                (%s, 'daily', 'total_abiertos', %s)
                ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)
            """, (
                today, semaforo.get('verde', 0),
                today, semaforo.get('amarillo', 0),
                today, semaforo.get('rojo', 0),
                today, semaforo.get('respondidos', 0),
                today, semaforo.get('total_abiertos', 0)
            ))
            
            # Actualizar métricas por agente
            agentes = metrics.get('agentes', [])
            for agente in agentes:
                cursor.execute("""
                    INSERT INTO agent_daily_metrics 
                    (agent_id, metric_date, cases_assigned, sla_compliance_rate)
                    VALUES (%s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE
                    cases_assigned = VALUES(cases_assigned),
                    sla_compliance_rate = VALUES(sla_compliance_rate),
                    updated_at = NOW()
                """, (
                    agente['agent_id'],
                    today,
                    agente.get('total_casos', 0),
                    agente.get('verde', 0) / max(agente.get('total_casos', 1), 1) * 100
                ))
            
            self.connection.commit()
            logger.info(f"Cache actualizado para {today}")
            return True
            
        except Exception as e:
            logger.error(f"Error actualizando cache: {e}")
            self.connection.rollback()
            return False
        finally:
            cursor.close()
    
    def send_alerts_if_needed(self, metrics: Dict[str, Any]):
        """Enviar alertas si hay casos críticos"""
        criticos = metrics.get('criticos', [])
        
        if criticos:
            logger.warning(f"ALERTA: {len(criticos)} casos en estado ROJO (4+ días)")
            
            # Aquí podrías integrar con:
            # - Email notifications
            # - Slack/Teams webhooks
            # - SMS alerts
            # - System notifications
            
            for caso in criticos[:5]:  # Solo primeros 5 para el log
                logger.warning(f"  Caso {caso['case_number']}: {caso['dias_desde_creacion']} días - {caso['assigned_to']}")
    
    def run(self):
        """Ejecutar worker completo"""
        if not self.connect():
            return
            
        try:
            # 1. Actualizar tracking SLA
            tracking_stats = self.update_sla_tracking()
            
            # 2. Calcular métricas del semáforo
            metrics = self.calculate_semaforo_metrics()
            
            # 3. Actualizar cache
            if metrics:
                self.update_performance_cache(metrics)
                
            # 4. Verificar alertas
            self.send_alerts_if_needed(metrics)
            
            # Log resumen
            semaforo = metrics.get('semaforo', {})
            logger.info(f"Resumen Semáforo: VERDE={semaforo.get('verde',0)} AMARILLO={semaforo.get('amarillo',0)} ROJO={semaforo.get('rojo',0)}")
            
        except Exception as e:
            logger.error(f"Error en worker: {e}")
        finally:
            if self.connection:
                self.connection.close()

if __name__ == "__main__":
    worker = SemaforoWorker()
    worker.run()