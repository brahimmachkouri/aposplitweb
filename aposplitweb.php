<?php
// BM 2025

// Exemple d'utilisation
/*
try {
    $splitter = new StudentPdfSplitter();
    StudentPdfSplitter::autoSplit('/path/to/your/pdf/file.pdf', '/path/to/output/directory');
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}
*/
require_once('vendor/autoload.php');

/**
 * Interface pour la gestion des PDF, permettant de séparer les responsabilités.
 */
interface IPdfHandler
{
    public function getPageCount(string $pdfPath): int;
    public function getPageText(string $pdfPath, int $pageIndex): string;
    public function addPageToDocument($document, string $pdfPath, int $pageIndex): void;
    public function createDocument();
    public function saveDocument($document, string $filePath): void;
    public function closeDocument($document): void;
}

/**
 * Implémentation de IPdfHandler utilisant des bibliothèques PHP pour les PDF
 */
class PdfHandler implements IPdfHandler
{
    /**
     * Retourne le nombre de pages d'un PDF
     */
    public function getPageCount(string $pdfPath): int
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        $pages = $pdf->getPages();
        return count($pages);
    }

    /**
     * Extraction du texte d'une page spécifique d'un PDF
     */
    public function getPageText(string $pdfPath, int $pageIndex): string
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($pdfPath);
        $pages = $pdf->getPages();
        
        if (isset($pages[$pageIndex])) {
            return $pages[$pageIndex]->getText();
        }
        
        return '';
    }

    /**
     * Crée un nouveau document PDF
     */
    public function createDocument()
    {
        return new \setasign\Fpdi\Fpdi();
    }

    /**
     * Ajoute une page d'un PDF source à un document PDF existant
     */
    public function addPageToDocument($document, string $pdfPath, int $pageIndex): void
    {
        if (!($document instanceof \setasign\Fpdi\Fpdi)) {
            throw new InvalidArgumentException('Le document fourni n\'est pas une instance valide de FPDI.');
        }
        
        $document->setSourceFile($pdfPath);
        $tplId = $document->importPage($pageIndex + 1);
        $document->AddPage();
        $document->useTemplate($tplId);
    }

    /**
     * Sauvegarde un document PDF
     */
    public function saveDocument($document, string $filePath): void
    {
        $document->Output($filePath, 'F');
    }

    /**
     * Ferme un document PDF (libère les ressources)
     */
    public function closeDocument($document): void
    {
        // PHP gère automatiquement la libération des ressources
        unset($document);
    }
}

/**
 * Classe pour les informations d'un étudiant
 */
class StudentPageInfo
{
    public ?string $name;
    public ?string $studentNumber;

    public function __construct(?string $name = null, ?string $studentNumber = null)
    {
        $this->name = $name;
        $this->studentNumber = $studentNumber;
    }
}

/**
 * Divise un PDF de relevés de notes en un fichier par étudiant
 */
class StudentPdfSplitter
{
    // Constantes pour regex/chaînes magiques
    private const STUDENT_NUMBER_REGEX = '/N°\s*Etudiant\s*[:\-]?\s*(\d+)/i';
    private const NAME_LINE_MARKER = 'Page : /';
    private const ATTESTATION_REGEX = '/edition\s*d[\'\']\s*attestations\s*de\s*r[ée]ussite/i';
    private const ATTESTATION_NAME_REGEX = '/crédits\s+européens\s*\n\s*(?:Monsieur|Madame|Mademoiselle)?\s*([A-ZÀ-ÿ\s\'-]+)\s*\n\s*a\s+été\s+décern/ius';
    private const ATTESTATION_FORMATION_REGEX = '/\bspécialité\s+(.+?)\s*\n/i';
    private const ATTESTATION_NUMETUD_REGEX = '/(\d+)\s*N°\s*étudiant\s*:/i';

    private static IPdfHandler $pdfHandler;

    public function __construct(?IPdfHandler $pdfHandler = null)
    {
        self::$pdfHandler = $pdfHandler ?? new PdfHandler();
    }

    /**
     * Division d'un PDF de relevés de notes en un fichier par étudiant
     */
    public static function splitByStudent(string $pdfPath, string $outputBaseDir): void
    {
        self::validateInputPaths($pdfPath, $outputBaseDir);

        $originalFileNameWithoutExt = pathinfo($pdfPath, PATHINFO_FILENAME);
        $sanitizedBaseName = self::sanitizeForFilename($originalFileNameWithoutExt);
        $studentOutputDir = $outputBaseDir . DIRECTORY_SEPARATOR . $sanitizedBaseName . '_per_student';

        if (!is_dir($studentOutputDir)) {
            mkdir($studentOutputDir, 0755, true);
        }

        if (!isset(self::$pdfHandler)) {
            self::$pdfHandler = new PdfHandler();
        }

        $studentDocuments = self::extractStudentDocuments($pdfPath, self::$pdfHandler);
        self::saveStudentDocuments($studentDocuments, $sanitizedBaseName, $studentOutputDir);

        echo "Traitement terminé : " . count($studentDocuments) . " fichiers créés dans $studentOutputDir\n";
    }

    /**
     * Validation des chemins d'entrée et de sortie
     */
    private static function validateInputPaths(string $pdfPath, string $outputBaseDir): void
    {
        if (empty($pdfPath)) {
            throw new InvalidArgumentException('pdfPath ne peut pas être vide');
        }
        if (!file_exists($pdfPath)) {
            throw new Exception("Le fichier PDF source n'a pas été trouvé: $pdfPath");
        }
        if (empty($outputBaseDir)) {
            throw new InvalidArgumentException('outputBaseDir ne peut pas être vide');
        }
    }

    /**
     * Extraction des pages des relevés de notes pour chaque étudiant
     */
    private static function extractStudentDocuments(string $pdfPath, IPdfHandler $pdfHandler): array
    {
        $results = [];
        $currentStudentInfo = null;
        $currentStudentPdf = null;

        // On suppose que la première page est une page de garde et on l'ignore
        $pageCount = $pdfHandler->getPageCount($pdfPath);
        
        for ($i = 1; $i < $pageCount; $i++) { // On ignore la première page
            // Extraction du texte de la page actuelle
            $pageText = $pdfHandler->getPageText($pdfPath, $i);
            $extractedInfo = self::parseStudentInfoFromText($pageText);

            // Vérification si on a un nouveau étudiant
            $isNewStudent = $currentStudentInfo === null ||
                           ($extractedInfo->studentNumber !== null && $extractedInfo->studentNumber !== $currentStudentInfo->studentNumber) ||
                           ($extractedInfo->studentNumber === null && $currentStudentInfo->studentNumber !== null);

            if ($isNewStudent) {
                if ($currentStudentPdf !== null && $currentStudentInfo !== null) {
                    $results[] = ['pdf' => $currentStudentPdf, 'info' => $currentStudentInfo];
                }

                $currentStudentInfo = $extractedInfo;
                $currentStudentPdf = $pdfHandler->createDocument();
            }

            $pdfHandler->addPageToDocument($currentStudentPdf, $pdfPath, $i);
        }

        // Ajout du dernier étudiant
        if ($currentStudentPdf !== null && $currentStudentInfo !== null) {
            $results[] = ['pdf' => $currentStudentPdf, 'info' => $currentStudentInfo];
        }

        return $results;
    }

    /**
     * Enregistrement des documents PDF des étudiants
     */
    private static function saveStudentDocuments(array $studentDocuments, string $baseName, string $outputDir): void
    {
        foreach ($studentDocuments as $doc) {
            self::saveStudentPdf($doc['pdf'], $doc['info'], $baseName, $outputDir);
        }
    }

    /**
     * Enregistrement du PDF d'un étudiant
     */
    private static function saveStudentPdf($studentPdf, StudentPageInfo $studentInfo, string $baseFileName, string $outputDir): void
    {
        $safeStudentName = self::sanitizeForFilename($studentInfo->name ?? 'NomNonTrouve');
        $safeStudentNumber = self::sanitizeForFilename($studentInfo->studentNumber ?? 'NumNonTrouve');
        $fileName = $baseFileName . '_' . $safeStudentName . '_' . $safeStudentNumber . '.pdf';
        $fullPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

        try {
            self::$pdfHandler->saveDocument($studentPdf, $fullPath);
            echo "✓ Fichier sauvegardé: $fileName\n";
        } catch (Exception $ex) {
            echo "Erreur lors de la sauvegarde du fichier $fullPath: " . $ex->getMessage() . "\n";
        } finally {
            self::$pdfHandler->closeDocument($studentPdf);
        }
    }

    /**
     * Analyse du texte d'une page pour extraire les informations de l'étudiant
     */
    private static function parseStudentInfoFromText(string $pageText): StudentPageInfo
    {
        $lines = preg_split('/[\n\r]+/', $pageText, -1, PREG_SPLIT_NO_EMPTY);
        $studentName = null;
        $studentNumber = null;

        //foreach ($lines as $line) {
        for ($i=0; $i < count($lines); $i++) {
            $trimmedLine = trim($lines[$i]);

            if ($studentName === null) {
                // Recherche du nom de l'étudiant
                $markerPos = stripos($trimmedLine, self::NAME_LINE_MARKER);
                if ($markerPos !== false) {
                    // Le nom est sur la ligne suivante
                    $studentName = trim($lines[$i+1]);

                    if (empty($studentName)) {
                        $studentName = null;
                    }
                }
            }

            // Recherche du numéro étudiant
            if ($studentNumber === null) {
                if (preg_match(self::STUDENT_NUMBER_REGEX, $trimmedLine, $matches)) {
                    $studentNumber = $matches[1];
                }
            }

            if ($studentName !== null && $studentNumber !== null) {
                break;
            }
        }

        return new StudentPageInfo($studentName, $studentNumber);
    }

    /**
     * Nettoie une chaîne pour qu'elle soit utilisable comme nom de fichier
     */
    public static function sanitizeForFilename(string $input): string
    {
        if (empty($input)) {
            return 'chaine_vide';
        }

        // Normalisation des caractères
        $normalized = self::removeAccents($input);
        $lowerCase = strtolower($normalized);
        
        // Remplacement des caractères non alphanumériques
        $replaced = preg_replace('/[^a-z0-9]/', '_', $lowerCase);
        $cleaned = preg_replace('/_+/', '_', $replaced);
        $cleaned = trim($cleaned, '_');

        if (empty($cleaned)) {
            return 'nettoyage_vide';
        }

        return $cleaned;
    }

    /**
     * Supprime les accents d'une chaîne
     */
    private static function removeAccents(string $string): string
    {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n'
        ];

        return strtr(strtolower($string), $accents);
    }

    /**
     * Vérifie si une page est une page d'attestation
     */
    private static function isAttestationPage(string $pageText): bool
    {
        return preg_match(self::ATTESTATION_REGEX, self::normalize($pageText));
    }

    /**
     * Parse les métadonnées d'une attestation
     */
    private static function parseMeta(string $pageText): array
    {
        $name = '';
        $formation = '';
        $ine = '';

        // Recherche du nom
        if (preg_match(self::ATTESTATION_NAME_REGEX, $pageText, $matches)) {
            $name = self::clean(trim($matches[1]));
        }

        // Recherche de la formation
        if (preg_match(self::ATTESTATION_FORMATION_REGEX, $pageText, $matches)) {
            $formation = self::clean(trim($matches[1]));
        }

        // Recherche du numéro étudiant
        if (preg_match(self::ATTESTATION_NUMETUD_REGEX, $pageText, $matches)) {
            $ine = self::clean($matches[1]);
        }

        // Valeurs par défaut
        if (empty($name)) $name = 'nan';
        if (empty($formation)) $formation = 'nan';
        if (empty($ine)) $ine = 'nan';

        echo "Résultat final: $name, $formation, $ine\n";

        return [
            self::normalize($formation),
            self::normalize($name),
            self::normalize($ine)
        ];
    }

    /**
     * Normalise une chaîne
     */
    private static function normalize(string $s): string
    {
        return strtolower(self::removeAccents($s));
    }

    /**
     * Nettoie une chaîne pour les noms de fichiers
     */
    private static function clean(string $s): string
    {
        if (empty(trim($s))) return 'x';
        
        // Supprime les caractères invalides pour les noms de fichiers
        $s = preg_replace('/[^\w\-]/', '_', $s);
        $s = preg_replace('/_+/', '_', trim($s, '_'));
        
        return empty($s) ? 'x' : $s;
    }

    /**
     * Détecte automatiquement le type de document et effectue la séparation adaptée
     */
    public static function autoSplit(string $pdfPath, string $outputBaseDir): void
    {
        if (empty($pdfPath)) {
            throw new InvalidArgumentException('pdfPath ne peut pas être vide');
        }
        if (!file_exists($pdfPath)) {
            throw new Exception("Le fichier PDF source n'a pas été trouvé: $pdfPath");
        }

        if (!isset(self::$pdfHandler)) {
            self::$pdfHandler = new PdfHandler();
        }

        // On lit le texte de la première page pour détecter le type de document
        $firstPageText = self::$pdfHandler->getPageText($pdfPath, 0);

        if (preg_match(self::ATTESTATION_REGEX, self::normalize($firstPageText))) {
            // Traitement des attestations
            $outDir = $outputBaseDir . DIRECTORY_SEPARATOR . pathinfo($pdfPath, PATHINFO_FILENAME) . '_attestations';
            
            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }

            $pageCount = self::$pdfHandler->getPageCount($pdfPath);
            
            // Vérifie que la première page est bien une édition d'attestations
            if (!self::isAttestationPage($firstPageText)) {
                echo "❓ Le document ne semble pas être « Édition d'attestations de réussite ».\n";
                return;
            }

            $saved = 0;
            for ($i = 1; $i < $pageCount; $i++) { // Skip first page
                $pageText = self::$pdfHandler->getPageText($pdfPath, $i);
                
                list($formation, $name, $ine) = self::parseMeta($pageText);
                $fileName = "$formation-$name-$ine.pdf";
                $fullPath = $outDir . DIRECTORY_SEPARATOR . $fileName;

                $singleDoc = self::$pdfHandler->createDocument();
                self::$pdfHandler->addPageToDocument($singleDoc, $pdfPath, $i);
                self::$pdfHandler->saveDocument($singleDoc, $fullPath);
                self::$pdfHandler->closeDocument($singleDoc);

                echo "✓ Page " . ($i + 1) . " → $fileName\n";
                $saved++;
            }

            echo $saved > 0 
                ? "Terminé : $saved attestation(s) enregistrée(s) dans « $outDir ».\n"
                : "Aucune page d'attestation détectée.\n";
        } else {
            // Il s'agit d'un relevé de notes
            self::splitByStudent($pdfPath, $outputBaseDir);
        }
    }
}

