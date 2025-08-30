<?php
require_once 'database/db.php';

header('Content-Type: application/json');
session_start();

// Configurar CORS si es necesario
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$db = new GalleryDB();
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'get_images':
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = 18;
            
            $images = $db->getImages($page, $perPage);
            $total = $db->getTotalImageCount();
            
            echo json_encode([
                'success' => true,
                'images' => $images,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage
            ]);
            break;
            
        case 'vote':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
            
            $imageId = intval($_POST['image_id']);
            $value = intval($_POST['value']);
            
            if ($value < 1 || $value > 5) {
                throw new Exception('Valor de voto inválido');
            }
            
            $ip = $_SERVER['REMOTE_ADDR'];
            $success = $db->addVote($imageId, $value, $ip);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Voto registrado' : 'Error al votar'
            ]);
            break;
            
        case 'comment':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
            
            $imageId = intval($_POST['image_id']);
            $author = trim($_POST['author'] ?? 'Anónimo');
            $content = trim($_POST['content'] ?? '');
            
            if (empty($content)) {
                throw new Exception('El comentario no puede estar vacío');
            }
            
            if (strlen($content) > 500) {
                throw new Exception('El comentario es demasiado largo (máximo 500 caracteres)');
            }
            
            $success = $db->addComment($imageId, $author, $content);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Comentario agregado' : 'Error al comentar'
            ]);
            break;
            
        case 'get_comments':
            $imageId = intval($_GET['image_id']);
            $comments = $db->getComments($imageId);
            
            echo json_encode([
                'success' => true,
                'comments' => $comments
            ]);
            break;
            
        case 'upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
    
            if (!isset($_FILES['image'])) {
                throw new Exception('No se ha subido ninguna imagen');
            }
    
            $file = $_FILES['image'];
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
                
            // Verificar si GD está instalado
            if (!extension_loaded('gd')) {
                throw new Exception('El servidor no soporta el procesamiento de imágenes (GD no         instalado)');
            }
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('Tipo de archivo no permitido. Solo se permiten JPEG, PNG,      GIF y WEBP');
            }
            
            if ($file['size'] > 10 * 1024 * 1024) { // Aumentado a 10MB para imágenes grandes antes de      redimensionar
                throw new Exception('El archivo es demasiado grande (máximo 10MB)');
            }
            
            $imageId = $db->uploadImage($file, $title, $description);
            
            if ($imageId) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Imagen subida y redimensionada correctamente',
                    'image_id' => $imageId
                ]);
            } else {
                throw new Exception('Error al guardar la imagen en la base de datos');
            }
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>