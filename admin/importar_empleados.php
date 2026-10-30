<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$empresaId = int_get('empresa_id');
$empresa = db_one('SELECT * FROM empresas WHERE id = ?', [$empresaId]);
if (!$empresa) {
    redirect('admin/empresas.php');
}
const COLUMNAS_IMPORT = ['codigo', 'nombre', 'apellido', 'email', 'area', 'puesto', 'nivel', 'email_jefe', 'sexo', 'fecha_ingreso'];

if (get('plantilla') === '1') {
    exportar_excel('plantilla_empleados', ['Empleados' => [
        COLUMNAS_IMPORT,
        ['1001', 'Ana', 'López', 'ana.lopez@empresa.com', 'Gerencia', 'Gerente General', 'Directivo', '', 'F', '2018-01-15'],
        ['1002', 'Carlos', 'Pérez', 'carlos.perez@empresa.com', 'Ventas', 'Jefe de Ventas', 'Mando medio', 'ana.lopez@empresa.com', 'M', '2020-03-01'],
        ['1003', 'María', 'García', 'maria.garcia@empresa.com', 'Ventas', 'Vendedora', 'Operativo', 'carlos.perez@empresa.com', 'F', '2022-06-10'],
    ]]);
}

function normalizar_encabezado(string $h): string
{
    $h = mb_strtolower(trim($h));
    $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    $alias = [
        'correo' => 'email', 'correo_electronico' => 'email', 'e_mail' => 'email', 'mail' => 'email',
        'apellidos' => 'apellido', 'nombres' => 'nombre', 'departamento' => 'area',
        'correo_jefe' => 'email_jefe', 'email_del_jefe' => 'email_jefe', 'jefe' => 'email_jefe', 'correo_del_jefe' => 'email_jefe', 'jefe_inmediato' => 'email_jefe',
        'codigo_empleado' => 'codigo', 'no_empleado' => 'codigo', 'genero' => 'sexo', 'fecha_de_ingreso' => 'fecha_ingreso',
    ];
    return $alias[trim($h, '_')] ?? trim($h, '_');
}

function fecha_import(string $v): ?string
{
    if ($v === '') {
        return null;
    }
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
        return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format($fmt) === $v) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

$preview = $_SESSION['import_empleados'][$empresaId] ?? null;

if (is_post()) {
    csrf_check();
    $accion = post('accion');
    if ($accion === 'cancelar') {
        unset($_SESSION['import_empleados'][$empresaId]);
        redirect('admin/importar_empleados.php?empresa_id=' . $empresaId);
    }
    if ($accion === 'analizar') {
        try {
            if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Seleccione un archivo.');
            }
            if ($_FILES['archivo']['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('El archivo excede 5 MB.');
            }
            $rows = leer_hoja($_FILES['archivo']['tmp_name'], $_FILES['archivo']['name']);
            if (count($rows) < 2) {
                throw new RuntimeException('El archivo no contiene filas de datos.');
            }
            $headers = array_map('normalizar_encabezado', array_shift($rows));
            foreach (['nombre', 'apellido', 'email'] as $req) {
                if (!in_array($req, $headers, true)) {
                    throw new RuntimeException("Falta la columna obligatoria \"$req\".");
                }
            }
            $existentes = [];
            foreach (db_all('SELECT id, email FROM empleados WHERE empresa_id = ?', [$empresaId]) as $r) {
                $existentes[strtolower($r['email'])] = (int)$r['id'];
            }
            $filas = [];
            $emailsArchivo = [];
            foreach ($rows as $i => $row) {
                $f = [];
                foreach (COLUMNAS_IMPORT as $c) {
                    $pos = array_search($c, $headers, true);
                    $f[$c] = $pos === false ? '' : trim((string)($row[$pos] ?? ''));
                }
                if (implode('', $f) === '') {
                    continue;
                }
                $f['email'] = strtolower($f['email']);
                $f['email_jefe'] = strtolower($f['email_jefe']);
                $f['fila'] = $i + 2;
                $f['errores'] = [];
                if ($f['nombre'] === '' || $f['apellido'] === '') {
                    $f['errores'][] = 'Nombre/apellido vacío';
                }
                if (!valid_email($f['email'])) {
                    $f['errores'][] = 'Correo inválido';
                } elseif (isset($emailsArchivo[$f['email']])) {
                    $f['errores'][] = 'Correo duplicado en el archivo (fila ' . $emailsArchivo[$f['email']] . ')';
                } else {
                    $emailsArchivo[$f['email']] = $f['fila'];
                }
                $f['sexo'] = strtoupper(substr($f['sexo'], 0, 1));
                if (!in_array($f['sexo'], ['M', 'F', 'O'], true)) {
                    $f['sexo'] = '';
                }
                $fi = fecha_import($f['fecha_ingreso']);
                if ($f['fecha_ingreso'] !== '' && !$fi) {
                    $f['errores'][] = 'Fecha de ingreso inválida';
                }
                $f['fecha_ingreso'] = $fi;
                $f['accion'] = isset($existentes[$f['email']]) ? 'actualizar' : 'nuevo';
                $filas[] = $f;
            }
            foreach ($filas as &$f) {
                if ($f['email_jefe'] !== '') {
                    if ($f['email_jefe'] === $f['email']) {
                        $f['errores'][] = 'El jefe no puede ser la misma persona';
                    } elseif (!isset($emailsArchivo[$f['email_jefe']]) && !isset($existentes[$f['email_jefe']])) {
                        $f['errores'][] = 'Correo del jefe no existe en el archivo ni en el padrón';
                    }
                }
            }
            unset($f);
            $_SESSION['import_empleados'][$empresaId] = $filas;
            redirect('admin/importar_empleados.php?empresa_id=' . $empresaId);
        } catch (Throwable $ex) {
            flash('danger', $ex->getMessage());
            redirect('admin/importar_empleados.php?empresa_id=' . $empresaId);
        }
    }
    if ($accion === 'confirmar' && $preview) {
        $validas = array_filter($preview, fn($f) => !$f['errores']);
        $desactivar = isset($_POST['desactivar_faltantes']);
        db()->beginTransaction();
        $areaCache = [];
        $nuevos = 0;
        $actualizados = 0;
        foreach ($validas as $f) {
            $areaId = null;
            if ($f['area'] !== '') {
                if (!isset($areaCache[$f['area']])) {
                    $aid = db_val('SELECT id FROM areas WHERE empresa_id = ? AND nombre = ?', [$empresaId, $f['area']]);
                    $areaCache[$f['area']] = $aid ? (int)$aid : db_insert('areas', ['empresa_id' => $empresaId, 'nombre' => $f['area']]);
                }
                $areaId = $areaCache[$f['area']];
            }
            $data = [
                'codigo' => $f['codigo'] ?: null, 'nombre' => $f['nombre'], 'apellido' => $f['apellido'], 'area_id' => $areaId,
                'puesto' => $f['puesto'] ?: null, 'nivel' => $f['nivel'] ?: null, 'sexo' => $f['sexo'] ?: null,
                'fecha_ingreso' => $f['fecha_ingreso'], 'activo' => 1,
            ];
            $id = db_val('SELECT id FROM empleados WHERE empresa_id = ? AND email = ?', [$empresaId, $f['email']]);
            if ($id) {
                db_update('empleados', $data, 'id = :id', ['id' => $id]);
                $actualizados++;
            } else {
                db_insert('empleados', $data + ['empresa_id' => $empresaId, 'email' => $f['email']]);
                $nuevos++;
            }
        }
        foreach ($validas as $f) {
            $jefeId = $f['email_jefe'] !== '' ? db_val('SELECT id FROM empleados WHERE empresa_id = ? AND email = ?', [$empresaId, $f['email_jefe']]) : null;
            db_exec('UPDATE empleados SET jefe_id = ? WHERE empresa_id = ? AND email = ?', [$jefeId ?: null, $empresaId, $f['email']]);
        }
        $bajas = 0;
        if ($desactivar && $validas) {
            $emails = array_map(fn($f) => $f['email'], $validas);
            $in = implode(',', array_fill(0, count($emails), '?'));
            $bajas = db_exec("UPDATE empleados SET activo = 0 WHERE empresa_id = ? AND activo = 1 AND email NOT IN ($in)", array_merge([$empresaId], $emails));
        }
        db()->commit();
        audit('importar_empleados', 'empresa', $empresaId, ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'bajas' => $bajas]);
        unset($_SESSION['import_empleados'][$empresaId]);
        flash('success', "Importación completada: $nuevos nuevos, $actualizados actualizados" . ($bajas ? ", $bajas dados de baja" : '') . '.');
        redirect('admin/empleados.php?empresa_id=' . $empresaId);
    }
}

render_header('Importar empleados');
page_title('Importar empleados: ' . $empresa['nombre'], '<a class="btn btn-outline-secondary" href="' . e(url('admin/empleados.php?empresa_id=' . $empresaId)) . '">Volver</a>');
if (!$preview): ?>
<div class="card"><div class="card-body">
  <p>Suba un archivo <strong>.xlsx</strong> o <strong>.csv</strong> con la primera fila de encabezados. Columnas reconocidas:</p>
  <p><code><?= e(implode(', ', COLUMNAS_IMPORT)) ?></code></p>
  <ul class="small">
    <li>Obligatorias: <strong>nombre, apellido, email</strong>. Para evaluaciones 90/180/270/360 se requiere <strong>email_jefe</strong>.</li>
    <li>Si el correo ya existe en la empresa, el empleado se actualiza; si no, se crea.</li>
    <li>Fechas en formato AAAA-MM-DD o DD/MM/AAAA. Sexo: M, F u O.</li>
  </ul>
  <p><a class="btn btn-sm btn-outline-success" href="?empresa_id=<?= $empresaId ?>&plantilla=1">Descargar plantilla Excel</a></p>
  <form method="post" enctype="multipart/form-data" class="row g-2">
    <?= csrf_field() ?><input type="hidden" name="accion" value="analizar">
    <div class="col-md-6"><input type="file" name="archivo" accept=".xlsx,.csv" class="form-control" required></div>
    <div class="col-auto"><button class="btn btn-primary">Analizar archivo</button></div>
  </form>
</div></div>
<?php else:
    $conError = count(array_filter($preview, fn($f) => $f['errores']));
    $nuevos = count(array_filter($preview, fn($f) => !$f['errores'] && $f['accion'] === 'nuevo'));
    $act = count(array_filter($preview, fn($f) => !$f['errores'] && $f['accion'] === 'actualizar'));
?>
<div class="alert alert-<?= $conError ? 'warning' : 'success' ?>">
  <?= count($preview) ?> filas leídas: <strong><?= $nuevos ?></strong> nuevas, <strong><?= $act ?></strong> a actualizar, <strong><?= $conError ?></strong> con errores (no se importarán).
</div>
<form method="post" class="mb-3 d-flex gap-2 align-items-center flex-wrap">
  <?= csrf_field() ?>
  <div class="form-check me-3"><input class="form-check-input" type="checkbox" name="desactivar_faltantes" id="df"><label class="form-check-label" for="df">Dar de baja a empleados que no estén en el archivo</label></div>
  <button name="accion" value="confirmar" class="btn btn-primary" <?= ($nuevos + $act) ? '' : 'disabled' ?>>Confirmar importación</button>
  <button name="accion" value="cancelar" class="btn btn-outline-secondary">Cancelar</button>
</form>
<div class="card"><div class="table-responsive" style="max-height:60vh"><table class="table table-sm mb-0">
  <thead><tr><th>Fila</th><th>Acción</th><th>Código</th><th>Nombre</th><th>Apellido</th><th>Correo</th><th>Área</th><th>Puesto</th><th>Correo jefe</th><th>Errores</th></tr></thead><tbody>
  <?php foreach ($preview as $f): ?>
  <tr class="<?= $f['errores'] ? 'table-danger' : '' ?>">
    <td><?= (int)$f['fila'] ?></td><td><?= $f['errores'] ? '—' : e($f['accion']) ?></td><td><?= e($f['codigo']) ?></td><td><?= e($f['nombre']) ?></td><td><?= e($f['apellido']) ?></td>
    <td><?= e($f['email']) ?></td><td><?= e($f['area']) ?></td><td><?= e($f['puesto']) ?></td><td><?= e($f['email_jefe']) ?></td>
    <td class="small text-danger"><?= e(implode('; ', $f['errores'])) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif;
render_footer();
