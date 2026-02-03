import os
import logging

logger = logging.getLogger("app.tls_bootstrap")

def bootstrap_tls_from_os_truststore() -> None:
    """
    En Windows (y algunos entornos corporativos) Python/httpx no confía
    en el CA corporativo porque usa certifi. truststore fuerza a usar
    el store del OS.
    """
    # Permite apagarlo si algún día lo necesitas:
    # TLS_TRUSTSTORE=0
    if os.getenv("TLS_TRUSTSTORE", "1").strip() in ("0", "false", "False", "no", "NO"):
        logger.warning("TLS truststore disabled by env TLS_TRUSTSTORE=0")
        return

    try:
        import truststore
        truststore.inject_into_ssl()
        logger.warning("TLS truststore injected (using OS certificate store)")
    except Exception as e:
        logger.warning("TLS truststore not available: %s", e)
