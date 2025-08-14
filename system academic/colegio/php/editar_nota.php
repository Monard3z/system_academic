<?php
include("conexion.php");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = $_POST['id'];
    $nota = $_POST['nota'];

    if (!is_numeric($nota) || $nota < 1 || $nota > 10) {
        die("Nota inválida.");
    }

    $stmt = $conn->prepare("UPDATE notas SET nota = ? WHERE id = ?");
    $stmt->bind_param("ii", $nota, $id);
    $stmt->execute();

    header("Location: ../profesor.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("ID no proporcionado.");
}

$id = $_GET['id'];
$stmt = $conn->prepare("
    SELECT n.*, u.nombre, u.apellido 
    FROM notas n 
    JOIN usuarios u ON n.alumno_id = u.id 
    WHERE n.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    die("Nota no encontrada.");
}

$nota = $resultado->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Nota</title>
    <link rel="stylesheet" href="../css/profesor.css">
</head>
<body>
    <div class="panel">
        <h2>✏️ Editar Nota</h2>
        <form method="POST" action="editar_nota.php">
            <input type="hidden" name="id" value="<?= $nota['id'] ?>">
            <p>Alumno: <?= htmlspecialchars($nota['apellido']) ?>, <?= htmlspecialchars($nota['nombre']) ?></p>
            <label>Nueva Nota:
                <input type="number" name="nota" value="<?= $nota['nota'] ?>" min="1" max="10" required>
            </label>
            <button type="submit">Guardar Cambios</button>
        </form>
    </div>
</body>
</html>
