<?php
declare(strict_types=1);

use function App\Config\url;

$_csrf = $_csrf ?? '';
?>
<div class="container-fluid py-3">

    <!-- Encabezado -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-cloud-upload text-primary me-2"></i>Importar Usuarios
            </h1>
            <p class="text-muted mb-0">Importa múltiples usuarios desde archivo Excel o CSV</p>
        </div>

        <div>
            <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mx-auto">

            <!-- Pasos del proceso -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h6 class="mb-0"><i class="bi bi-list-ol me-2"></i>Pasos para Importar</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3 mb-3">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3 mx-auto mb-2" style="width: 70px; height: 70px;">
                                <i class="bi bi-download display-6 text-primary"></i>
                            </div>
                            <h6 class="mb-1">1. Descargar Plantilla</h6>
                            <p class="text-muted small">Usa el formato correcto</p>
                        </div>

                        <div class="col-md-3 mb-3">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3 mx-auto mb-2" style="width: 70px; height: 70px;">
                                <i class="bi bi-pencil display-6 text-primary"></i>
                            </div>
                            <h6 class="mb-1">2. Llenar Datos</h6>
                            <p class="text-muted small">Completa la información</p>
                        </div>

                        <div class="col-md-3 mb-3">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3 mx-auto mb-2" style="width: 70px; height: 70px;">
                                <i class="bi bi-upload display-6 text-primary"></i>
                            </div>
                            <h6 class="mb-1">3. Subir Archivo</h6>
                            <p class="text-muted small">Verifica los datos</p>
                        </div>

                        <div class="col-md-3 mb-3">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3 mx-auto mb-2" style="width: 70px; height: 70px;">
                                <i class="bi bi-check-all display-6 text-primary"></i>
                            </div>
                            <h6 class="mb-1">4. Confirmar</h6>
                            <p class="text-muted small">Revisa el resultado</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario de importación -->
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <!-- Plantilla -->
                    <div class="mb-4">
                        <h5 class="mb-3">
                            <i class="bi bi-file-earmark-excel text-success me-2"></i>Plantilla
                        </h5>

                        <div class="alert alert-info">
                            <div class="d-flex">
                                <div class="flex-shrink-0">
                                    <i class="bi bi-info-circle-fill"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="alert-heading mb-2">Formato requerido</h6>
                                    <p class="mb-2">Descarga la plantilla para asegurar una importación exitosa.</p>
                                    <p class="mb-0">
                                        <strong>Columnas obligatorias:</strong> Username, Email, Nombre Completo<br>
                                        <strong>Opcionales:</strong> Documento, Roles (separados por coma)
                                    </p>
                                </div>
                            </div>
                        </div>

                        <a href="<?= esc(url('/admin/users/export-template')) ?>"
                           class="btn btn-success">
                            <i class="bi bi-download me-2"></i>Descargar Plantilla
                        </a>
                    </div>

                    <!-- Instrucciones -->
                    <div class="mb-4">
                        <h5 class="mb-3">
                            <i class="bi bi-lightbulb text-warning me-2"></i>Instrucciones
                        </h5>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex">
                                <i class="bi bi-check text-success me-2"></i>
                                <span><strong>Username:</strong> Debe ser único en el sistema</span>
                            </li>
                            <li class="list-group-item d-flex">
                                <i class="bi bi-check text-success me-2"></i>
                                <span><strong>Email:</strong> Debe tener formato válido y ser único</span>
                            </li>
                            <li class="list-group-item d-flex">
                                <i class="bi bi-check text-success me-2"></i>
                                <span><strong>Roles:</strong> Usa códigos: ADMIN, SUPERVISOR, AGENTE</span>
                            </li>
                            <li class="list-group-item d-flex">
                                <i class="bi bi-check text-success me-2"></i>
                                <span><strong>Documento:</strong> Opcional, pero debe ser único si se proporciona</span>
                            </li>
                            <li class="list-group-item d-flex">
                                <i class="bi bi-check text-success me-2"></i>
                                <span><strong>Password:</strong> Se generará automáticamente si no se especifica</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Subir archivo -->
                    <div class="mb-4">
                        <h5 class="mb-3">
                            <i class="bi bi-cloud-arrow-up text-primary me-2"></i>Subir Archivo
                        </h5>

                        <form method="post"
                              action="<?= esc(url('/admin/users/import')) ?>"
                              enctype="multipart/form-data"
                              class="needs-validation"
                              novalidate>
                            <input type="hidden" name="_csrf" value="<?= esc($_csrf) ?>">

                            <div class="mb-3">
                                <label for="excelFile" class="form-label">Archivo Excel/CSV</label>
                                <input class="form-control"
                                       type="file"
                                       id="excelFile"
                                       name="excel_file"
                                       accept=".xlsx,.xls,.csv"
                                       required>
                                <div class="invalid-feedback">
                                    Por favor selecciona un archivo Excel o CSV.
                                </div>
                                <div class="form-text">
                                    Formatos aceptados: .xlsx, .xls, .csv (Máximo 5MB)
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="send_welcome_email"
                                           id="sendWelcomeEmail"
                                           value="1">
                                    <label class="form-check-label" for="sendWelcomeEmail">
                                        Enviar email de bienvenida con credenciales temporales
                                    </label>
                                </div>
                                <div class="form-text">
                                    Los usuarios recibirán un email con sus credenciales de acceso.
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="skip_duplicates"
                                           id="skipDuplicates"
                                           value="1"
                                           checked>
                                    <label class="form-check-label" for="skipDuplicates">
                                        Omitir usuarios duplicados (por email o username)
                                    </label>
                                </div>
                                <div class="form-text">
                                    Los usuarios existentes no serán sobrescritos.
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-upload me-2"></i>Iniciar Importación
                                </button>
                                <a href="<?= esc(url('/admin/users')) ?>" class="btn btn-outline-secondary">
                                    Cancelar
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Validación previa -->
                    <div class="alert alert-warning mb-0">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="alert-heading">Validación previa</h6>
                                <p class="mb-0">
                                    El sistema validará automáticamente los datos antes de importar.
                                    Se mostrará un resumen con los usuarios que se crearán y cualquier error encontrado.
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

</div>

<script>
// Validación del formulario (Bootstrap) + validación de archivo
document.addEventListener('DOMContentLoaded', function() {
  const forms = document.querySelectorAll('.needs-validation');

  Array.from(forms).forEach(form => {
    form.addEventListener('submit', function(event) {
      const fileInput = form.querySelector('input[type="file"]');
      let fileOk = true;

      if (fileInput && fileInput.files.length > 0) {
        const file = fileInput.files[0];
        const maxSize = 5 * 1024 * 1024; // 5MB

        // Algunos browsers no mandan MIME confiable; por eso validamos también por extensión
        const extOk = /\.(xlsx|xls|csv)$/i.test(file.name || '');
        const mimeOk = [
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'application/vnd.ms-excel',
          'text/csv',
          'application/vnd.ms-excel.sheet.macroEnabled.12',
          'application/vnd.ms-excel.sheet.binary.macroEnabled.12'
        ].includes(file.type);

        if (file.size > maxSize) {
          fileOk = false;
          alert('El archivo es demasiado grande (máximo 5MB).');
        } else if (!mimeOk && !extOk) {
          fileOk = false;
          alert('Tipo de archivo no permitido. Use .xlsx, .xls o .csv.');
        }
      }

      if (!form.checkValidity() || !fileOk) {
        event.preventDefault();
        event.stopPropagation();
      }

      form.classList.add('was-validated');
    }, false);
  });

  // Mostrar nombre de archivo
  const fileInput = document.getElementById('excelFile');
  if (fileInput) {
    fileInput.addEventListener('change', function() {
      const fileName = this.files[0]?.name || 'Ningún archivo seleccionado';
      const label = document.querySelector('label[for="excelFile"]');
      if (label) label.textContent = `Archivo: ${fileName}`;
    });
  }
});
</script>
