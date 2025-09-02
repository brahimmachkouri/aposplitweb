<?php
// (c) Brahim MACHKOURI 2025
// Script pour diviser un PDF de relevés de notes ou attestations en un fichier par étudiant
// version 1.3

require_once('vendor/autoload.php');

/**
 * Interface pour la gestion des PDF
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
    private ?\Smalot\PdfParser\Document $cachedPdf = null;
    private ?string $cachedPdfPath = null;

    /**
     * Parse un PDF et retourne les pages (avec mise en cache)
     */
    private function getParsedPages(string $pdfPath): array
    {
         // Utilise le cache si c'est le même fichier
        if ($this->cachedPdf === null || $this->cachedPdfPath !== $pdfPath) {
            $parser = new \Smalot\PdfParser\Parser();
            $this->cachedPdf = $parser->parseFile($pdfPath);
            $this->cachedPdfPath = $pdfPath;
        }
        return $this->cachedPdf->getPages();
    }

    /**
     * Retourne le nombre de pages d'un PDF
     */
    public function getPageCount(string $pdfPath): int
    {
        return count($this->getParsedPages($pdfPath));
    }

    /**
     * Extraction du texte d'une page spécifique d'un PDF
     */
    public function getPageText(string $pdfPath, int $pageIndex): string
    {
        $pages = $this->getParsedPages($pdfPath);
        return isset($pages[$pageIndex]) ? $pages[$pageIndex]->getText() : '';
    }

    /**
     * Création d'un nouveau document PDF
     */
    public function createDocument()
    {
        return new \setasign\Fpdi\Fpdi();
    }

    /**
     * Ajout d'une page à un document PDF
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
     * Enregistre un document PDF
     */
    public function saveDocument($document, string $filePath): void
    {
        $document->Output($filePath, 'F');
    }

    /**
     * Ferme un document PDF (libère les ressources et le cache)
     */
    public function closeDocument($document): void
    {
        $this->cachedPdf = null;
        $this->cachedPdfPath = null;
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
 * Divise un PDF d'Apogée en un fichier par étudiant
 */
class StudentPdfSplitter
{
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
     * Divise un PDF de relevés de notes en un fichier par étudiant
     */
    public static function splitByStudent(string $pdfPath, string $outputBaseDir): void
    {
        self::validatePaths($pdfPath, $outputBaseDir);
        self::initPdfHandler();

        $baseName = self::sanitizeForFilename(pathinfo($pdfPath, PATHINFO_FILENAME));
        $outputDir = $outputBaseDir . DIRECTORY_SEPARATOR . $baseName . '_per_student';
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $studentDatas = self::extractStudentDatas($pdfPath);
        self::saveStudentDocuments($studentDatas, $baseName, $outputDir);

        error_log("Traitement terminé : " . count($studentDatas) . " fichiers créés dans $outputDir\n");
    }

    /**
     * Divise un PDF d'attestations en un fichier par attestation
     */
    public static function splitAttestations(string $pdfPath, string $outputBaseDir): void
    {
        self::initPdfHandler();
        
        $outDir = $outputBaseDir . DIRECTORY_SEPARATOR . pathinfo($pdfPath, PATHINFO_FILENAME) . '_attestations';
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $pageCount = self::$pdfHandler->getPageCount($pdfPath);
        $saved = 0;
        
        for ($i = 1; $i < $pageCount; $i++) {
            $pageText = self::$pdfHandler->getPageText($pdfPath, $i);
            
            $formation = self::parseDatas($pageText, self::ATTESTATION_FORMATION_REGEX);
            $name = self::parseDatas($pageText, self::ATTESTATION_NAME_REGEX);
            $ine = self::parseDatas($pageText, self::ATTESTATION_NUMETUD_REGEX);

            $fileName = "$formation-$name-$ine.pdf";
            $fullPath = $outDir . DIRECTORY_SEPARATOR . $fileName;

            $singleDoc = self::$pdfHandler->createDocument();
            self::$pdfHandler->addPageToDocument($singleDoc, $pdfPath, $i);
            self::$pdfHandler->saveDocument($singleDoc, $fullPath);
            self::$pdfHandler->closeDocument($singleDoc);

            error_log("✓ Page " . ($i + 1) . " → $fileName\n");
            $saved++;
        }

        error_log($saved > 0 
            ? "Terminé : $saved attestation(s) enregistrée(s) dans « $outDir ».\n"
            : "Aucune page d'attestation détectée.\n");
    }

    /**
     * Divise un PDF en plusieurs fichiers selon le type de document
     */
    public static function autoSplit(string $pdfPath, string $outputBaseDir): void
    {
        self::validatePaths($pdfPath, $outputBaseDir);
        self::initPdfHandler();

        $firstPageText = self::$pdfHandler->getPageText($pdfPath, 0);

        if (preg_match(self::ATTESTATION_REGEX, self::normalize($firstPageText))) {
            self::splitAttestations($pdfPath, $outputBaseDir);
        } else {
            self::splitByStudent($pdfPath, $outputBaseDir);
        }
    }

    /**
     * Valide les chemins d'accès au PDF et au répertoire de sortie
     */
    private static function validatePaths(string $pdfPath, string $outputBaseDir): void
    {
        if (empty($pdfPath) || !file_exists($pdfPath)) {
            throw new Exception("Le fichier PDF source n'existe pas: $pdfPath");
        }
        if (empty($outputBaseDir)) {
            throw new InvalidArgumentException('outputBaseDir ne peut pas être vide');
        }
    }

    /**
     * Initialise le gestionnaire de PDF
     */
    private static function initPdfHandler(): void
    {
        if (!isset(self::$pdfHandler)) {
            self::$pdfHandler = new PdfHandler();
        }
    }

    /**
     * Extraction des datas étudiants d'un PDF
     */
    private static function extractStudentDatas(string $pdfPath): array
    {
        $results = [];
        $currentStudentInfo = null;
        $currentStudentPdf = null;
        $pageCount = self::$pdfHandler->getPageCount($pdfPath);
        
        for ($i = 1; $i < $pageCount; $i++) {
            $pageText = self::$pdfHandler->getPageText($pdfPath, $i);
            $extractedInfo = self::parseStudentInfoFromText($pageText);

            $isNewStudent = $currentStudentInfo === null ||
                           ($extractedInfo->studentNumber !== null && $extractedInfo->studentNumber !== $currentStudentInfo->studentNumber) ||
                           ($extractedInfo->studentNumber === null && $currentStudentInfo->studentNumber !== null);

            if ($isNewStudent) {
                if ($currentStudentPdf !== null && $currentStudentInfo !== null) {
                    $results[] = ['pdf' => $currentStudentPdf, 'info' => $currentStudentInfo];
                }
                $currentStudentInfo = $extractedInfo;
                $currentStudentPdf = self::$pdfHandler->createDocument();
            }

            self::$pdfHandler->addPageToDocument($currentStudentPdf, $pdfPath, $i);
        }

        if ($currentStudentPdf !== null && $currentStudentInfo !== null) {
            $results[] = ['pdf' => $currentStudentPdf, 'info' => $currentStudentInfo];
        }

        return $results;
    }

    /**
     * Sauvegarde les documents PDF des étudiants
     */
    private static function saveStudentDocuments(array $studentDocuments, string $baseName, string $outputDir): void
    {
        foreach ($studentDocuments as $doc) {
            $safeStudentName = self::sanitizeForFilename($doc['info']->name ?? 'NomNonTrouve');
            $safeStudentNumber = self::sanitizeForFilename($doc['info']->studentNumber ?? 'NumNonTrouve');
            $fileName = $baseName . '_' . $safeStudentName . '_' . $safeStudentNumber . '.pdf';
            $fullPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

            try {
                self::$pdfHandler->saveDocument($doc['pdf'], $fullPath);
                error_log("✓ Fichier sauvegardé: $fileName\n");
            } catch (Exception $ex) {
                error_log("Erreur lors de la sauvegarde du fichier $fullPath: " . $ex->getMessage() . "\n");
            } finally {
                self::$pdfHandler->closeDocument($doc['pdf']);
            }
        }
    }

    /**
     * Extraction des informations d'un étudiant à partir du texte d'une page
     */
    private static function parseStudentInfoFromText(string $pageText): StudentPageInfo
    {
        $lines = preg_split('/[\n\r]+/', $pageText, -1, PREG_SPLIT_NO_EMPTY);
        $studentName = null;
        $studentNumber = null;

        for ($i = 0; $i < count($lines); $i++) {
            $trimmedLine = trim($lines[$i]);

            if ($studentName === null && stripos($trimmedLine, self::NAME_LINE_MARKER) !== false) {
                $studentName = trim($lines[$i + 1] ?? '');
            }

            if ($studentNumber === null && preg_match(self::STUDENT_NUMBER_REGEX, $trimmedLine, $matches)) {
                $studentNumber = $matches[1];
            }

            if ($studentName !== null && $studentNumber !== null) {
                break;
            }
        }

        return new StudentPageInfo($studentName, $studentNumber);
    }

    /**
     * Nettoie une chaîne pour l'utiliser comme nom de fichier
     */
    private static function sanitizeForFilename(string $input): string
    {
        if (empty(trim($input))) return 'chaine_vide';
        
        $normalized = self::removeAccents($input);
        $cleaned = preg_replace('/[^a-z0-9]/', '_', strtolower($normalized));
        return preg_replace('/_+/', '_', trim($cleaned, '_'));
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
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 
            'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 
            'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'
        ];
        return strtr(strtolower($string), $accents);
    }

    /**
     * Normalise une chaîne en minuscules et sans accents
     */
    private static function normalize(string $s): string
    {
        return strtolower(self::removeAccents($s));
    }

    /**
     * Analyse le texte d'une page pour en extraire des données
     */
    private static function parseDatas(string $pageText, string $regex): string
    {
        if (preg_match($regex, $pageText, $matches)) {
            return self::sanitizeForFilename(trim($matches[1]));
        }
        return 'nan';
    }
}
