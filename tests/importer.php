<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/Config.php';
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'TrangThu\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use TrangThu\Import\ChapterSplitter;
use TrangThu\Import\DocumentExtractor;

$path = tempnam(sys_get_temp_dir(), 'trang-thu-import-');
if ($path === false) {
    throw new RuntimeException('Could not create importer test fixture.');
}

try {
    file_put_contents($path, "Chương 1: Mở đầu\n\nMột câu chuyện.\n\nChương 2: Tiếp nối\n\nHai trang mới.");
    $extracted = (new DocumentExtractor())->extract($path, 'txt');
    $chapters = (new ChapterSplitter())->split($extracted);
    if (count($chapters) !== 2 || $chapters[0]['title'] !== 'Chương 1: Mở đầu'
        || $chapters[1]['chapter_number'] !== 2) {
        throw new RuntimeException('Chapter headings were not preserved during import.');
    }

    if (class_exists(ZipArchive::class)) {
        $archivePath = $path . '.docx';
        $archive = new ZipArchive();
        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create DOCX test fixture.');
        }
        $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>Chương 1: Tệp DOCX</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Nội dung mẫu.</w:t></w:r></w:p></w:body></w:document>';
        $archive->addFromString('word/document.xml', $xml);
        $archive->close();
        $docxText = (new DocumentExtractor())->extract($archivePath, 'docx');
        if (!str_contains($docxText, 'Chương 1: Tệp DOCX') || !str_contains($docxText, 'Nội dung mẫu.')) {
            throw new RuntimeException('DOCX paragraphs were not extracted.');
        }
        unlink($archivePath);
    }

    $pdfParser = 'Smalot\\PdfParser\\Parser';
    if (class_exists($pdfParser)) {
        $pdfPath = $path . '.pdf';
        $stream = 'BT /F1 12 Tf 72 720 Td (PDF import sample) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . count($offsets) . "\n0000000000 65535 f \n";
        for ($index = 1; $index < count($offsets); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }
        $pdf .= "trailer\n<< /Size " . count($offsets) . " /Root 1 0 R >>\nstartxref\n"
            . $xrefOffset . "\n%%EOF";
        file_put_contents($pdfPath, $pdf);
        $pdfText = (new DocumentExtractor())->extract($pdfPath, 'pdf');
        if (!str_contains($pdfText, 'PDF import sample')) {
            throw new RuntimeException('PDF text was not extracted.');
        }
        unlink($pdfPath);
    }

    $largeText = implode("\n\n", array_fill(0, 7001, 'tu'));
    $parts = (new ChapterSplitter())->split($largeText);
    if (count($parts) !== 2 || $parts[0]['word_count'] !== 7000 || $parts[1]['word_count'] !== 1) {
        throw new RuntimeException('Long chapters were not split at the configured paragraph boundary.');
    }
    $submittedParts = (new ChapterSplitter())->splitChapter('Chương thử', $largeText, 3);
    if (count($submittedParts) !== 2 || $submittedParts[0]['chapter_number'] !== 3
        || $submittedParts[1]['part_number'] !== 2 || $submittedParts[0]['title'] !== 'Chương thử'
        || $submittedParts[1]['title'] !== 'Chương thử') {
        throw new RuntimeException('Submitted chapters were not split with their title and sequence.');
    }
    $customParts = (new ChapterSplitter(2))->splitChapter('Chương cấu hình', "một hai\n\nba");
    if (count($customParts) !== 2 || $customParts[0]['word_count'] !== 2 || $customParts[1]['part_number'] !== 2) {
        throw new RuntimeException('Configured chapter word limits were not applied.');
    }

    fwrite(STDOUT, "Importer text extraction, chapter splitting, and available DOCX/PDF checks passed.\n");
} finally {
    unlink($path);
    foreach ([$path . '.docx', $path . '.pdf'] as $fixture) {
        if (is_file($fixture)) unlink($fixture);
    }
}
