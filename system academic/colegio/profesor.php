<?php
include("php/verificar_rol.php");
verificarRol('profesor');
include("php/conexion.php");

$id_profesor      = $_SESSION['usuario']['id'];
$nombre_profesor  = $_SESSION['usuario']['nombre'] . ' ' . $_SESSION['usuario']['apellido'];

/* ---------------- Cursos asignados ---------------- */
$cursos_result = $conn->prepare("SELECT curso FROM profesor_curso WHERE profesor_id = ?");
$cursos_result->bind_param("i", $id_profesor);
$cursos_result->execute();
$res_cursos = $cursos_result->get_result();
$cursos = [];
while ($fila = $res_cursos->fetch_assoc()) { $cursos[] = $fila['curso']; }
$curso_seleccionado = $_GET['curso'] ?? ($cursos[0] ?? '');

/* ---------------- Filtros ---------------- */
$materia_id_sel = isset($_GET['materia_id']) ? intval($_GET['materia_id']) : 0;
$trimestre_sel  = isset($_GET['trimestre'])  ? intval($_GET['trimestre'])  : 0;

/* ---------------- Guías ---------------- */
if ($materia_id_sel > 0) {
    $stmt_guias = $conn->prepare("SELECT * FROM guias WHERE curso = ? AND profesor_id = ? AND materia_id = ? ORDER BY fecha_subida DESC");
    $stmt_guias->bind_param("sii", $curso_seleccionado, $id_profesor, $materia_id_sel);
} else {
    $stmt_guias = $conn->prepare("SELECT * FROM guias WHERE curso = ? AND profesor_id = ? ORDER BY fecha_subida DESC");
    $stmt_guias->bind_param("si", $curso_seleccionado, $id_profesor);
}
$stmt_guias->execute();
$guias = $stmt_guias->get_result();

/* ---------------- Notas (para tabla y gráfico) ---------------- */
$sql_notas = "
    SELECT n.*, u.nombre, u.apellido, m.nombre AS materia_nombre
    FROM notas n
    JOIN usuarios u ON n.alumno_id = u.id
    LEFT JOIN materias m ON n.materia_id = m.id
    WHERE n.profesor_id = ? AND u.curso = ?
";
$params = [$id_profesor, $curso_seleccionado];
$types  = "is";
if ($materia_id_sel > 0) { $sql_notas .= " AND n.materia_id = ?"; $params[] = $materia_id_sel; $types .= "i"; }
if (in_array($trimestre_sel, [1,2,3], true)) { $sql_notas .= " AND n.trimestre = ?"; $params[] = $trimestre_sel; $types .= "i"; }
$sql_notas .= " ORDER BY u.apellido";

$notas_stmt = $conn->prepare($sql_notas);
$notas_stmt->bind_param($types, ...$params);

/* ---------------- Promedios (general y por trimestre) ---------------- */
$sql_prom = "
    SELECT nota, trimestre
    FROM notas n
    JOIN usuarios u ON n.alumno_id = u.id
    WHERE n.profesor_id = ? AND u.curso = ?
";
$p_params = [$id_profesor, $curso_seleccionado];
$p_types  = "is";
if ($materia_id_sel > 0) { $sql_prom .= " AND n.materia_id = ?"; $p_params[] = $materia_id_sel; $p_types .= "i"; }

$prom_stmt = $conn->prepare($sql_prom);
$prom_stmt->bind_param($p_types, ...$p_params);
$prom_stmt->execute();
$prom_res = $prom_stmt->get_result();

$sumGeneral = 0; $cntGeneral = 0;
$sumT = [1=>0,2=>0,3=>0]; $cntT = [1=>0,2=>0,3=>0];
while ($r = $prom_res->fetch_assoc()) {
    $nota = (float)$r['nota'];
    $trim = (int)$r['trimestre'];
    $sumGeneral += $nota; $cntGeneral++;
    if (isset($sumT[$trim])) { $sumT[$trim] += $nota; $cntT[$trim]++; }
}
$promedio_curso = $cntGeneral ? round($sumGeneral/$cntGeneral, 2) : 0;
$promTrim1 = $cntT[1] ? round($sumT[1]/$cntT[1], 2) : 0;
$promTrim2 = $cntT[2] ? round($sumT[2]/$cntT[2], 2) : 0;
$promTrim3 = $cntT[3] ? round($sumT[3]/$cntT[3], 2) : 0;

/* ---------------- Datos para el gráfico ---------------- */
$promedios_por_alumno = [];    // alumno => [1=>[],2=>[],3=>[]]
// 1ª pasada para el gráfico
$notas_stmt->execute();
$res_graf = $notas_stmt->get_result();
while ($n = $res_graf->fetch_assoc()) {
    $alumno = $n['apellido'] . ', ' . $n['nombre'];
    $t = (int)($n['trimestre'] ?? 0);
    if ($t < 1 || $t > 3) continue;
    if (!isset($promedios_por_alumno[$alumno])) {
        $promedios_por_alumno[$alumno] = [1=>[],2=>[],3=>[]];
    }
    $promedios_por_alumno[$alumno][$t][] = (float)$n['nota'];
}
$labels = array_keys($promedios_por_alumno);
$promedios_trimestre = [1=>[],2=>[],3=>[]];
foreach ($promedios_por_alumno as $al => $trims) {
    for ($t=1; $t<=3; $t++) {
        $arr = $trims[$t];
        $promedios_trimestre[$t][] = count($arr) ? round(array_sum($arr)/count($arr), 2) : 0;
    }
}

// 2ª pasada para la tabla
$notas_stmt->execute();
$notas = $notas_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel del Profesor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Tu CSS existente -->
    <link rel="stylesheet" href="css/profesor.css">
    <style>
        /* Ajustes sutiles para margen y tarjeta de promedios */
        main { max-width: 1100px; margin: 0 auto; padding: 16px; }
        .tarjeta-promedios { border-radius: 10px; padding: 12px; margin: 10px 0 22px; }
        .prom-wrapper { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
        .prom-item { border-radius: 8px; padding: 10px 12px; font-weight: 600; text-align: center; }
        .prom-item small { display:block; font-weight: 400; opacity: .8; }
        @media (max-width: 768px){ .prom-wrapper{ grid-template-columns: 1fr; } }

        /* Gráfico más pequeño y responsivo */
        #grafico-container { max-width: 900px; margin: 18px auto; }
        #graficoNotas { width: 100%; height: 260px; }

        /* iconitos más espaciados en guías/tabla */
        .icon { margin-left: 8px; }
    </style>
</head>
<body>
<header>
    <h1>👨‍🏫 Bienvenido, <?= htmlspecialchars($nombre_profesor) ?></h1>
    <a href="php/cerrar_sesion.php" class="boton-cerrar">Cerrar sesión</a>
    <button onclick="toggleTema()" class="boton-tema" title="Cambiar tema">🌓</button>
</header>

<main>
    <section>
        <h2>🎓 Curso y Materia</h2>
        <form method="GET" class="formulario formulario-curso">
            <label>Curso:</label>
            <select name="curso" id="selectCurso" onchange="this.form.submit()">
                <?php foreach ($cursos as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $c === $curso_seleccionado ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Materia:</label>
            <select name="materia_id" id="selectMateria" onchange="this.form.submit()">
                <option value="0" <?= $materia_id_sel===0?'selected':'' ?>>Todas</option>
            </select>

            <label>Trimestre (filtro):</label>
            <select name="trimestre" onchange="this.form.submit()">
                <option value="0" <?= $trimestre_sel===0?'selected':'' ?>>Todos</option>
                <option value="1" <?= $trimestre_sel===1?'selected':'' ?>>1</option>
                <option value="2" <?= $trimestre_sel===2?'selected':'' ?>>2</option>
                <option value="3" <?= $trimestre_sel===3?'selected':'' ?>>3</option>
            </select>
        </form>

        <!-- Panel de promedios -->
        <div class="tarjeta-promedios">
            <h3>📊 Promedios <?= $materia_id_sel>0?'(Materia seleccionada)':'' ?></h3>
            <div class="prom-wrapper">
                <div class="prom-item">General: <?= number_format((float)$promedio_curso, 2) ?></div>
                <div class="prom-item">1er Trim.: <?= number_format((float)$promTrim1, 2) ?></div>
                <div class="prom-item">2do Trim.: <?= number_format((float)$promTrim2, 2) ?></div>
                <div class="prom-item">3er Trim.: <?= number_format((float)$promTrim3, 2) ?></div>
            </div>
        </div>
    </section>

    <section>
        <h2>📤 Subir Guía</h2>
        <form action="php/subir_guia.php" method="POST" enctype="multipart/form-data" class="formulario">
            <input type="hidden" name="profesor_id" value="<?= $id_profesor ?>">
            <input type="hidden" name="curso" value="<?= htmlspecialchars($curso_seleccionado) ?>">

            <label>Título:</label>
            <input type="text" name="titulo" required>

            <label>Materia:</label>
            <select name="materia_id" id="materiaParaGuia" required></select>

            <label>Archivo (PDF/DOCX):</label>
            <input type="file" name="archivo" accept=".pdf,.docx" required>

            <button type="submit">Subir</button>
        </form>
    </section>

    <section>
        <h2>📚 Guías subidas</h2>
        <?php if ($guias->num_rows > 0): ?>
            <ul class="lista-guias">
                <?php while ($g = $guias->fetch_assoc()): ?>
                    <li>
                        <span>
                            <?= htmlspecialchars($g['titulo']) ?> - <?= date("d/m/Y", strtotime($g['fecha_subida'])) ?>
                            <?php if (!empty($g['materia_id'])):
                                $mat = $conn->query("SELECT nombre FROM materias WHERE id = " . intval($g['materia_id']))->fetch_assoc();
                            ?>
                                <em>(<?= htmlspecialchars($mat['nombre'] ?? 'Materia') ?>)</em>
                            <?php endif; ?>
                        </span>
                        <span>
                            <a class="icon" href="php/descargar_guia.php?archivo=<?= urlencode($g['archivo']) ?>" title="Ver/Descargar">📥</a>
                            <a class="icon" href="php/editar_guia.php?id=<?= $g['id'] ?>" title="Editar">✏</a>
                            <a class="icon" href="php/eliminar_guia.php?id=<?= $g['id'] ?>" onclick="return confirm('¿Eliminar esta guía?')" title="Eliminar">🗑️</a>
                        </span>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p>No hay guías subidas para este curso<?= $materia_id_sel>0?' y materia seleccionada':'' ?>.</p>
        <?php endif; ?>
    </section>

    <section>
        <h2>📝 Registrar Nota</h2>
        <form action="php/guardar_nota.php" method="POST" class="formulario">
            <input type="hidden" name="profesor_id" value="<?= $id_profesor ?>">
            <input type="hidden" name="curso" value="<?= htmlspecialchars($curso_seleccionado) ?>">

            <label>Alumno:</label>
            <select name="alumno_id" required>
                <?php
                $alumnos = $conn->query("SELECT id, nombre, apellido FROM usuarios WHERE curso = '" . $conn->real_escape_string($curso_seleccionado) . "' AND rol = 'alumno' ORDER BY apellido");
                while ($a = $alumnos->fetch_assoc()): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['apellido'] . ', ' . $a['nombre']) ?></option>
                <?php endwhile; ?>
            </select>

            <label>Materia:</label>
            <select name="materia_id" id="materiaParaNota" required></select>

            <label>Trimestre:</label>
            <select name="trimestre" required>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
            </select>

            <label>Nota (1 a 10):</label>
            <input type="number" name="nota" min="1" max="10" step="0.01" required>

            <button type="submit">Guardar</button>
        </form>
    </section>

    <section>
        <h2>📋 Notas registradas</h2>
        <?php if ($notas->num_rows > 0): ?>
            <table class="tabla-notas">
                <thead>
                    <tr>
                        <th>Alumno</th>
                        <th>Materia</th>
                        <th>Trimestre</th>
                        <th>Nota</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($n = $notas->fetch_assoc()): ?>
                    <tr>
                        <td data-label="Alumno"><?= htmlspecialchars($n['apellido'] . ', ' . $n['nombre']) ?></td>
                        <td data-label="Materia"><?= htmlspecialchars($n['materia_nombre'] ?? 'Sin materia') ?></td>
                        <td data-label="Trimestre"><?= (int)$n['trimestre'] ?></td>
                        <td data-label="Nota"><?= $n['nota'] ?></td>
                        <td data-label="Acciones">
                            <a class="icon" href="php/editar_nota.php?id=<?= $n['id'] ?>" title="Editar">✏</a>
                            <a class="icon" href="php/eliminar_nota.php?id=<?= $n['id'] ?>" onclick="return confirm('¿Eliminar esta nota?')" title="Eliminar">🗑️</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>

            <p class="acciones">
                <a class="boton-exportar" href="php/exportar_notas_excel.php?curso=<?= urlencode($curso_seleccionado) ?>&materia_id=<?= $materia_id_sel ?>">📊 Exportar a Excel</a>
            </p>
        <?php else: ?>
            <p>No hay notas registradas.</p>
        <?php endif; ?>
    </section>

    <section>
        <h2>📈 Rendimiento por Alumno y Trimestre<?= $materia_id_sel>0?' (Materia seleccionada)':'' ?></h2>
        <button onclick="toggleGrafico()">Mostrar/Ocultar gráfico</button>
        <div id="grafico-container" class="grafico-container">
            <canvas id="graficoNotas"></canvas>
        </div>
    </section>
</main>

<script src="js/chart.min.js"></script>
<script>
function toggleTema() {
    document.body.classList.toggle("oscuro");
    localStorage.setItem("tema", document.body.classList.contains("oscuro") ? "oscuro" : "claro");
}
window.addEventListener("DOMContentLoaded", () => {
    if (localStorage.getItem("tema") === "oscuro") document.body.classList.add("oscuro");
    cargarMaterias();
});

/* Mostrar/ocultar gráfico */
function toggleGrafico() {
    const el = document.getElementById("grafico-container");
    el.classList.toggle("visible");
}

/* Cargar materias según curso seleccionado */
function cargarMaterias() {
    const curso = document.getElementById("selectCurso").value;
    const url = `php/obtener_materias.php?profesor_id=<?= $id_profesor ?>&curso=${encodeURIComponent(curso)}`;
    fetch(url).then(r => r.json()).then(data => {
        const selFiltro = document.getElementById("selectMateria");
        const selGuia   = document.getElementById("materiaParaGuia");
        const selNota   = document.getElementById("materiaParaNota");
        [selFiltro, selGuia, selNota].forEach(sel => { if (!sel) return; sel.innerHTML = ""; });

        if (selFiltro) {
            const opt0 = document.createElement("option");
            opt0.value = 0; opt0.textContent = "Todas";
            if (<?= $materia_id_sel ?> === 0) opt0.selected = true;
            selFiltro.appendChild(opt0);
        }

        data.forEach(m => {
            ["selectMateria","materiaParaGuia","materiaParaNota"].forEach(id => {
                const sel = document.getElementById(id);
                if (!sel) return;
                const op = document.createElement("option");
                op.value = m.id; op.textContent = m.nombre;
                sel.appendChild(op);
            });
        });

        // mantener seleccionada la materia si viene por GET
        const materiaSel = <?= $materia_id_sel ?>;
        if (selFiltro && materiaSel > 0) selFiltro.value = materiaSel;
    });
}

/* ---- Datos del gráfico desde PHP ---- */
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const datosT1 = <?= json_encode($promedios_trimestre[1] ?? [], JSON_UNESCAPED_UNICODE) ?>;
const datosT2 = <?= json_encode($promedios_trimestre[2] ?? [], JSON_UNESCAPED_UNICODE) ?>;
const datosT3 = <?= json_encode($promedios_trimestre[3] ?? [], JSON_UNESCAPED_UNICODE) ?>;

if (document.getElementById('graficoNotas')) {
    const ctx = document.getElementById('graficoNotas').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: '1er Trimestre', data: datosT1, backgroundColor: 'rgba(54, 162, 235, 0.6)' },
                { label: '2do Trimestre', data: datosT2, backgroundColor: 'rgba(255, 206, 86, 0.6)' },
                { label: '3er Trimestre', data: datosT3, backgroundColor: 'rgba(75, 192, 192, 0.6)' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false, // permite que respete el height del canvas
            plugins: { title: { display: true, text: 'Promedios por Alumno y Trimestre' } },
            scales: { y: { beginAtZero: true, suggestedMax: 10 } }
        }
    });
}
</script>
</body>
</html>
