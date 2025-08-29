<?php
require_once 'vendor/autoload.php';
require_once 'aposplitweb.php';


// Configuration des chemins
$inputDir = '/var/www/html/input';
$outputDir = '/var/www/html/output';

// Liste des fichiers PDF dans le répertoire input
$pdfFiles = glob($inputDir . '/*.pdf');

echo "<!doctype html><html lang='fr'><head><meta charset='utf-8'><title>Test</title></head><body>";
echo "<h1>Test de StudentPdfSplitter</h1>\n<br/>";

if (empty($pdfFiles)) {
    echo "❌ Aucun fichier PDF trouvé dans $inputDir\n<br/>";
    echo "Placez vos fichiers PDF dans le répertoire input via Portainer ou docker cp\n";
    exit(1);
}

echo "Fichiers PDF trouvés :\n<br/>";
foreach ($pdfFiles as $file) {
    echo "- " . basename($file) . "\n<br/>";
}
echo "\n<br/>";

// Test du splitter sur chaque fichier
foreach ($pdfFiles as $pdfFile) {
    echo "=== Traitement de : " . basename($pdfFile) . " ===\n<br/>";
    
    try {
        $splitter = new StudentPdfSplitter();
        StudentPdfSplitter::autoSplit($pdfFile, $outputDir);
        echo "✅ Traitement terminé avec succès\n\n<br/>";
    } catch (Exception $e) {
        echo "❌ Erreur lors du traitement : " . $e->getMessage() . "\n\n<br/>";
    }
}

echo "=== Test terminé ===\n<br/>";
echo "Vérifiez les résultats dans le répertoire output\n<br/>";

echo "</body></html>";