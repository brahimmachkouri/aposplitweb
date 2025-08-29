<?php
$inputDir = '/var/www/html/input';
$outputDir = '/var/www/html/output';

// Gérer l'upload de fichiers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdfFile'])) {
    $uploadFile = $inputDir . '/' . basename($_FILES['pdfFile']['name']);
    
    if (move_uploaded_file($_FILES['pdfFile']['tmp_name'], $uploadFile)) {
        $uploadMessage = "✅ Fichier uploadé avec succès : " . basename($_FILES['pdfFile']['name']);
    } else {
        $uploadMessage = "❌ Erreur lors de l'upload du fichier.";
    }
}

// Lister les fichiers input
function listFiles($dir, $extension = '.pdf') {
    if (!is_dir($dir)) return [];
    $files = scandir($dir);
    return array_filter($files, function($file) use ($extension) {
        return pathinfo($file, PATHINFO_EXTENSION) === ltrim($extension, '.');
    });
}

$inputFiles = listFiles($inputDir);
$outputFiles = listFiles($outputDir);
$allOutputFiles = [];
// Inclure aussi les sous-dossiers
foreach (glob($outputDir . '/*', GLOB_ONLYDIR) as $subdir) {
    $subdirName = basename($subdir);
    $subFiles = listFiles($subdir);
    foreach ($subFiles as $file) {
        $allOutputFiles[] = $subdirName . '/' . $file;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Splitter - Interface de test</title>
    <style>
        body { 
            font-family: 'Segoe UI', Arial, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 40px;
            color: #333;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            background: linear-gradient(45deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .upload-section {
            background: #f8f9ff;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 2px dashed #667eea;
        }
        .file-input {
            margin: 20px 0;
        }
        .file-input input[type="file"] {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100%;
        }
        button {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .files-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 30px 0;
        }
        .files-section {
            background: #f9f9f9;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        .files-section h3 {
            color: #333;
            margin-top: 0;
            font-size: 1.3em;
        }
        .file-list {
            max-height: 200px;
            overflow-y: auto;
        }
        .file-item {
            padding: 8px 12px;
            margin: 5px 0;
            background: white;
            border-radius: 5px;
            border-left: 3px solid #667eea;
            font-family: monospace;
            font-size: 14px;
        }
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            font-weight: 500;
        }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #e2e3ea; color: #383d41; border: 1px solid #d6d8db; }
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
        }
        @media (max-width: 768px) {
            .files-grid { grid-template-columns: 1fr; }
            .container { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 AposplitWeb</h1>
            <p>Interface de développement et test</p>
        </div>

        <?php if (isset($uploadMessage)): ?>
            <div class="message <?= strpos($uploadMessage, '✅') !== false ? 'success' : 'error' ?>">
                <?= htmlspecialchars($uploadMessage) ?>
            </div>
        <?php endif; ?>

        <div class="upload-section">
            <h3>📁 Upload d'un fichier PDF</h3>
            <form method="post" enctype="multipart/form-data">
                <div class="file-input">
                    <input type="file" name="pdfFile" accept=".pdf" required>
                </div>
                <button type="submit">📤 Upload fichier</button>
            </form>
        </div>

        <div class="files-grid">
            <div class="files-section">
                <h3>📥 Fichiers Input (<?= count($inputFiles) ?>)</h3>
                <div class="file-list">
                    <?php if (empty($inputFiles)): ?>
                        <div class="message info">Aucun fichier PDF dans le répertoire input</div>
                    <?php else: ?>
                        <?php foreach ($inputFiles as $file): ?>
                            <div class="file-item">📄 <?= htmlspecialchars($file) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="files-section">
                <h3>📤 Fichiers Output (<?= count($allOutputFiles) ?>)</h3>
                <div class="file-list">
                    <?php if (empty($allOutputFiles)): ?>
                        <div class="message info">Aucun fichier généré</div>
                    <?php else: ?>
                        <?php foreach ($allOutputFiles as $file): ?>
                            <div class="file-item">✅ <?= htmlspecialchars($file) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="actions">
            <button onclick="window.location.href='http://localhost:8080/test.php'">
                🚀 Exécuter le splitter
            </button>
            <button onclick="location.reload()">
                🔄 Actualiser
            </button>
        </div>

        <div class="files-section">
            <h3>ℹ️ Instructions</h3>
            <div style="line-height: 1.6;">
                <p><strong>Pour tester votre script :</strong></p>
                <ol>
                    <li>Uploadez un fichier PDF via le formulaire ci-dessus</li>
                    <li>Cliquez sur "Exécuter le splitter" pour traiter le fichier</li>
                    <li>Vérifiez les résultats dans la section "Fichiers Output"</li>
                </ol>
                <p><strong>Accès direct aux containers :</strong></p>
                <ul>
                    <li>Code PHP principal : <code>http://localhost:8080</code></li>
                    <li>Container : <code>docker exec -it aposplitweb bash</code></li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
