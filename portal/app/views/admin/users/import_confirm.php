<?php
declare(strict_types=1);

use function App\Config\url;

$importedUsers = $importedUsers ?? [];
$_csrf = $_csrf ?? '';
?>

<div class="container-fluid py-3">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-envelope-check text-primary me-2"></i>Confirmar Envío de Emails
            </h1>
            <p class="text-muted mb-0">Revisa los usuarios importados antes de enviar los emails de bienvenida</p>
        </div>
        
        <div>
            <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Omitir y volver
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mx-auto">
            
            <div class="card shadow-sm">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Confirmación Requerida</h6>
                </div>
                <div class="card-body">
                    
                    <div class="alert alert-info mb-4">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <i class="bi bi-info-circle-fill"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="alert-heading mb-2">Información importante</h6>
                                <p class="mb-0">
                                    Se importaron <strong><?= count($importedUsers) ?></strong> usuarios exitosamente. 
                                    A continuación se listan los usuarios que recibirán el email de bienvenida con sus credenciales.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de usuarios importados -->
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Contraseña Temporal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($importedUsers as $index => $user): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= esc($user['username']) ?></td>
                                        <td><?= esc($user['email']) ?></td>
                                        <td>
                                            <code><?= esc($user['password']) ?></code>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-secondary ms-2"
                                                    onclick="copyToClipboard('<?= esc($user['password']) ?>', this)">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Formulario de confirmación -->
                    <div class="border-top pt-4">
                        <form method="post" action="<?= esc(url('/admin/users/send-welcome-emails')) ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="confirmSend"
                                           required>
                                    <label class="form-check-label" for="confirmSend">
                                        Confirmo que he revisado la información y deseo enviar los emails de bienvenida
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="emailSubject" class="form-label">Asunto del email (opcional)</label>
                                <input type="text" 
                                       class="form-control" 
                                       id="emailSubject"
                                       name="email_subject"
                                       placeholder="Bienvenido al Sistema ICBF Mail"
                                       value="Bienvenido al Sistema ICBF Mail">
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary me-md-2">
                                    <i class="bi bi-x-circle me-1"></i>Omitir envío
                                </a>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-envelope-paper me-1"></i>Enviar Emails de Bienvenida
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>

            <!-- Advertencia -->
            <div class="alert alert-warning mt-4">
                <div class="d-flex">
                    <div class="flex-shrink-0">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="alert-heading mb-2">Nota importante</h6>
                        <p class="mb-0">
                            Una vez enviados los emails, no podrás recuperar las contraseñas temporales. 
                            Asegúrate de guardar esta información si es necesario.
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(() => {
        const icon = button.querySelector('i');
        icon.className = 'bi bi-check';
        button.classList.remove('btn-outline-secondary');
        button.classList.add('btn-success');
        
        setTimeout(() => {
            icon.className = 'bi bi-clipboard';
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-secondary');
        }, 2000);
    });
}

// Validación del formulario
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    form.addEventListener('submit', function(e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Enviando...';
            submitBtn.disabled = true;
        }
    });
});
</script>