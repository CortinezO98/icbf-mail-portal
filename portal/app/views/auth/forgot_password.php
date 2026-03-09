<?php
use App\Auth\Csrf;
use function App\Config\url;

$year = date('Y');
$emailValue = htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ICBF Mail - Recuperar acceso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 20px;
        }

        .forgot-container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
        }

        /* LOGOS - EXACTAMENTE COMO EN LA IMAGEN */
        .forgot-logos {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 24px;
        }

        .forgot-logo-icbf {
            height: 80px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.05));
        }

        .forgot-logo-iq {
            height: 60px;
            width: auto;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.05));
        }

        /* TÍTULO PRINCIPAL */
        .forgot-main-title {
            text-align: center;
            font-size: 1.75rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }

        /* TARJETA PRINCIPAL - SIN HEADER */
        .forgot-card {
            background: #ffffff;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 28px;
            padding: 32px 28px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
            transition: transform 0.2s ease;
        }

        .forgot-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 30px 60px -12px rgba(0,0,0,0.2);
        }

        /* TEXTO DE DESCRIPCIÓN */
        .forgot-description {
            text-align: center;
            margin-bottom: 28px;
        }

        .forgot-description-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .forgot-description-text {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* CAMPO DE EMAIL */
        .forgot-input-group {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            transition: all 0.2s ease;
            background: #ffffff;
        }

        .forgot-input-group:focus-within {
            border-color: #4CAF50;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1);
        }

        .forgot-input-icon {
            background: #ffffff;
            border: none;
            padding-left: 16px;
            color: #94a3b8;
        }

        .forgot-input-icon i {
            font-size: 1.2rem;
        }

        .forgot-input {
            border: none;
            padding: 14px 16px 14px 8px;
            font-size: 1rem;
        }

        .forgot-input:focus {
            outline: none;
            box-shadow: none;
        }

        .forgot-input::placeholder {
            color: #cbd5e1;
        }

        .form-label {
            font-weight: 500;
            color: #334155;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }

        /* BOTÓN PRINCIPAL */
        .forgot-btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            border: none;
            border-radius: 14px;
            padding: 16px 24px;
            font-weight: 600;
            font-size: 1.1rem;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: 16px;
            position: relative;
            overflow: hidden;
        }

        .forgot-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .forgot-btn:active::after {
            width: 300px;
            height: 300px;
        }

        .forgot-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -8px rgba(76, 175, 80, 0.5);
        }

        .forgot-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* BOTÓN VOLVER */
        .forgot-back-btn {
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 24px;
            color: #475569;
            font-weight: 500;
            width: 100%;
            margin-top: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .forgot-back-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }

        /* CARACTERÍSTICAS DE SEGURIDAD */
        .forgot-security {
            display: flex;
            justify-content: center;
            gap: 32px;
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
        }

        .forgot-security-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            color: #475569;
        }

        .forgot-security-item i {
            font-size: 1.4rem;
            color: #4CAF50;
        }

        .forgot-security-item small {
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* FOOTER */
        .forgot-footer {
            text-align: center;
            margin-top: 24px;
            color: #94a3b8;
            font-size: 0.85rem;
        }

        /* ALERTAS */
        .alert-custom {
            border: none;
            border-radius: 14px;
            padding: 14px 18px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .alert-success-custom {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #4CAF50;
        }

        .alert-danger-custom {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        /* ANIMACIONES */
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .spinner-custom {
            width: 20px;
            height: 20px;
            border-width: 2px;
            border-color: white transparent white transparent;
        }

        .invalid-feedback {
            font-size: 0.8rem;
            margin-top: 6px;
            color: #ef4444;
        }

        /* RESPONSIVE */
        @media (max-width: 480px) {
            .forgot-card {
                padding: 24px 20px;
            }

            .forgot-logo-icbf {
                height: 60px;
            }

            .forgot-logo-iq {
                height: 45px;
            }

            .forgot-security {
                gap: 20px;
            }

            .forgot-main-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="forgot-container fade-in-up">

    <!-- LOGOS -->
    <div class="forgot-logos animate__animated animate__fadeInDown">
        <img
            src="<?= htmlspecialchars(url('/assets/img/logo_icbf.png'), ENT_QUOTES, 'UTF-8') ?>"
            alt="ICBF"
            class="forgot-logo-icbf"
        >
        <img
            src="<?= htmlspecialchars(url('/assets/img/logo_iq.png'), ENT_QUOTES, 'UTF-8') ?>"
            alt="IQ Outsourcing"
            class="forgot-logo-iq"
        >
    </div>

    <!-- TÍTULO PRINCIPAL -->
    <h1 class="forgot-main-title animate__animated animate__fadeInDown">
        Recuperación de acceso
    </h1>

    <!-- TARJETA PRINCIPAL (SIN HEADER) -->
    <div class="forgot-card">

        <!-- DESCRIPCIÓN -->
        <div class="forgot-description">
            <div class="forgot-description-title">Recuperación segura</div>
            <div class="forgot-description-text">
                Ingresa tu correo electrónico y te enviaremos un enlace seguro para restablecer tu contraseña.
            </div>
        </div>

        <!-- ALERTAS -->
        <?php if (!empty($message)): ?>
            <div class="alert-custom alert-success-custom" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-custom alert-danger-custom" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- FORMULARIO -->
        <form method="post" action="<?= htmlspecialchars(url('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>" id="forgotForm" novalidate>
            <input
                type="hidden"
                name="_csrf"
                value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>"
            >

            <div class="mb-4">
                <label for="email" class="form-label">Correo electrónico</label>
                <div class="input-group forgot-input-group">
                    <span class="input-group-text forgot-input-icon">
                        <i class="bi bi-envelope"></i>
                    </span>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        class="form-control forgot-input"
                        placeholder="usuario@dominio.com"
                        required
                        autocomplete="email"
                        value="<?= $emailValue ?>"
                    >
                </div>
                <div class="invalid-feedback" id="emailError">
                    Por favor ingresa un correo electrónico válido.
                </div>
            </div>

            <button type="submit" class="forgot-btn" id="submitBtn">
                <span class="btn-label">
                    <i class="bi bi-send-check me-2"></i>Enviar enlace de recuperación
                </span>
                <span class="spinner-border spinner-border-sm spinner-custom ms-2 d-none" id="submitSpinner" role="status"></span>
            </button>

            <a
                href="<?= htmlspecialchars(url('/login'), ENT_QUOTES, 'UTF-8') ?>"
                class="forgot-back-btn"
            >
                <i class="bi bi-arrow-left me-2"></i>Volver al inicio de sesión
            </a>
        </form>

        <!-- CARACTERÍSTICAS DE SEGURIDAD -->
        <div class="forgot-security">
            <div class="forgot-security-item">
                <i class="bi bi-shield-check"></i>
                <small>Enlace seguro</small>
            </div>
            <div class="forgot-security-item">
                <i class="bi bi-clock-history"></i>
                <small>Válido 30 min</small>
            </div>
            <div class="forgot-security-item">
                <i class="bi bi-incognito"></i>
                <small>Confidencial</small>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="forgot-footer">
        <small>© <?= (int)$year ?> ICBF • IQ Outsourcing</small>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('forgotForm');
    const email = document.getElementById('email');
    const submitBtn = document.getElementById('submitBtn');
    const submitSpinner = document.getElementById('submitSpinner');
    const btnLabel = submitBtn ? submitBtn.querySelector('.btn-label') : null;
    const emailError = document.getElementById('emailError');

    // Validación en tiempo real
    if (email) {
        email.addEventListener('input', function () {
            if (this.validity.valid) {
                this.classList.remove('is-invalid');
                if (emailError) emailError.style.display = 'none';
            }
        });

        email.addEventListener('blur', function () {
            if (this.value.trim() !== '' && !this.validity.valid) {
                this.classList.add('is-invalid');
                if (emailError) emailError.style.display = 'block';
            } else {
                this.classList.remove('is-invalid');
                if (emailError) emailError.style.display = 'none';
            }
        });
    }

    // Submit del formulario
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                
                if (email && !email.validity.valid) {
                    email.classList.add('is-invalid');
                    if (emailError) emailError.style.display = 'block';
                }
                
                return;
            }

            // Efecto de carga
            if (submitBtn && submitSpinner && btnLabel) {
                submitBtn.disabled = true;
                submitSpinner.classList.remove('d-none');
                btnLabel.style.opacity = '0.8';
            }
        });
    }

    // Limpiar spinner si la página se recarga
    window.addEventListener('pageshow', function() {
        if (submitBtn && submitSpinner && btnLabel) {
            submitBtn.disabled = false;
            submitSpinner.classList.add('d-none');
            btnLabel.style.opacity = '1';
        }
    });
});
</script>

</body>
</html>