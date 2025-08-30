<?php
class GalleryDB {
    private $db;
    
    public function __construct() {
        $dbPath = __DIR__ . '/gallery.db';
        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        $this->initializeDB();
    }
    
    private function initializeDB() {
        $tablesExist = $this->db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='images'");
        
        if (!$tablesExist) {
            $this->db->exec('BEGIN TRANSACTION');
            
            // Tabla de imágenes
            $this->db->exec('
                CREATE TABLE images (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename TEXT UNIQUE NOT NULL,
                    filepath TEXT NOT NULL,
                    title TEXT,
                    description TEXT,
                    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ');
            
            // Tabla de votos
            $this->db->exec('
                CREATE TABLE votes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    image_id INTEGER NOT NULL,
                    vote_value INTEGER NOT NULL CHECK (vote_value BETWEEN 1 AND 5),
                    voter_ip TEXT,
                    vote_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
                )
            ');
            
            // Tabla de comentarios
            $this->db->exec('
                CREATE TABLE comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    image_id INTEGER NOT NULL,
                    author TEXT NOT NULL DEFAULT "Anónimo",
                    content TEXT NOT NULL,
                    comment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
                )
            ');
            
            $this->db->exec('COMMIT');
        }
    }
    
    public function getImages($page = 1, $perPage = 18) {
        $offset = ($page - 1) * $perPage;
        
        $query = "
            SELECT 
                i.id,
                i.filename,
                i.filepath,
                i.title,
                i.upload_date,
                COUNT(DISTINCT v.id) AS vote_count,
                ROUND(AVG(v.vote_value), 1) AS average_rating,
                COUNT(DISTINCT c.id) AS comment_count
            FROM 
                images i
            LEFT JOIN 
                votes v ON i.id = v.image_id
            LEFT JOIN 
                comments c ON i.id = c.image_id
            GROUP BY 
                i.id
            ORDER BY 
                i.upload_date DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $images = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $images[] = $row;
        }
        
        return $images;
    }
    
    public function getTotalImageCount() {
        return $this->db->querySingle("SELECT COUNT(*) FROM images");
    }
    
    public function addVote($imageId, $value, $ip = null) {
        $stmt = $this->db->prepare("
            INSERT INTO votes (image_id, vote_value, voter_ip) 
            VALUES (:image_id, :value, :ip)
        ");
        $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
        $stmt->bindValue(':value', $value, SQLITE3_INTEGER);
        $stmt->bindValue(':ip', $ip ?? $_SERVER['REMOTE_ADDR'], SQLITE3_TEXT);
        
        return $stmt->execute();
    }
    
    public function addComment($imageId, $author, $content) {
        $stmt = $this->db->prepare("
            INSERT INTO comments (image_id, author, content) 
            VALUES (:image_id, :author, :content)
        ");
        $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
        $stmt->bindValue(':author', $author, SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        
        return $stmt->execute();
    }
    
    public function getComments($imageId) {
        $stmt = $this->db->prepare("
            SELECT * FROM comments 
            WHERE image_id = :image_id 
            ORDER BY comment_date DESC
        ");
        $stmt->bindValue(':image_id', $imageId, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        $comments = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $comments[] = $row;
        }
        
        return $comments;
    }
    
    public function uploadImage($file, $title = '', $description = '') {
    $uploadDir = __DIR__ . '/../images/';
    
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = uniqid() . '_' . basename($file['name']);
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Redimensionar la imagen
        $this->resizeImage($filepath);
        
        $stmt = $this->db->prepare("
            INSERT INTO images (filename, filepath, title, description) 
            VALUES (:filename, :filepath, :title, :description)
        ");
        $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
        $stmt->bindValue(':filepath', 'images/' . $filename, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            return $this->db->lastInsertRowID();
        }
    }
    
    return false;
}

    private function resizeImage($filePath, $maxWidth = 1024) {
    // Obtener información de la imagen
    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) return false;
    
    list($originalWidth, $originalHeight, $type) = $imageInfo;
    
    // Si la imagen ya es más pequeña que el máximo, no redimensionar
    if ($originalWidth <= $maxWidth) return true;
    
    // Calcular nuevas dimensiones manteniendo proporciones
    $ratio = $originalHeight / $originalWidth;
    $newWidth = $maxWidth;
    $newHeight = round($maxWidth * $ratio);
    
    // Crear imagen según el tipo
    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($filePath);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($filePath);
            break;
        case IMAGETYPE_WEBP:
            $image = imagecreatefromwebp($filePath);
            break;
        default:
            return false;
    }
    
    if (!$image) return false;
    
    // Crear nueva imagen redimensionada
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preservar transparencia para PNG y GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
    }
    
    // Redimensionar
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
    
    // Guardar la imagen redimensionada
    $result = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($newImage, $filePath, 85); // Calidad 85%
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($newImage, $filePath, 6); // Nivel de compresión 6
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($newImage, $filePath);
            break;
        case IMAGETYPE_WEBP:
            $result = imagewebp($newImage, $filePath, 85); // Calidad 85%
            break;
    }
    
    // Liberar memoria
    imagedestroy($image);
    imagedestroy($newImage);
    
    return $result;
}

    
    public function __destruct() {
        $this->db->close();
    }
}
?>