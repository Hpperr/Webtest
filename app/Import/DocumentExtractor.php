<?php

declare(strict_types=1);

namespace TrangThu\Import;

use DOMDocument;
use RuntimeException;
use ZipArchive;

final class DocumentExtractor
{
    public function extract(string $path, string $type): string
    {
        $text = match ($type) {
            'txt' => $this->readText($path),
            'docx' => $this->readDocx($path),
            'pdf' => $this->readPdf($path),
            default => throw new RuntimeException('Định dạng nhập không được hỗ trợ.'),
        };
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/(?<!\n)\n(?!\n)/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5_000_000) {
            throw new RuntimeException('Tài liệu trống hoặc vượt quá giới hạn nội dung xử lý.');
        }

        return $text;
    }

    private function readText(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false || !mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('Tệp văn bản phải dùng mã hóa UTF-8.');
        }
        return $text;
    }

    private function readDocx(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP cần bật tiện ích ZIP để đọc tệp DOCX.');
        }
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw new RuntimeException('Tệp DOCX không hợp lệ.');
        }
        try {
            $entry = $archive->statName('word/document.xml');
            if (!is_array($entry) || (int) ($entry['size'] ?? 0) > 50_000_000) {
                throw new RuntimeException('Tệp DOCX không có nội dung chính hợp lệ hoặc quá lớn.');
            }
            $xml = $archive->getFromName('word/document.xml');
            if (!is_string($xml) || strlen($xml) > 50_000_000) {
                throw new RuntimeException('Không thể đọc nội dung tệp DOCX.');
            }
        } finally {
            $archive->close();
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new RuntimeException('Cấu trúc XML trong tệp DOCX không hợp lệ.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $paragraphs = [];
        foreach ($document->getElementsByTagNameNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'p'
        ) as $paragraph) {
            $parts = [];
            foreach ($paragraph->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                't'
            ) as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $line = trim(implode('', $parts));
            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    private function readPdf(string $path): string
    {
        $parserClass = 'Smalot\\PdfParser\\Parser';
        if (!class_exists($parserClass)) {
            throw new RuntimeException('Cần cài thư viện smalot/pdfparser để xử lý PDF.');
        }
        $parser = new $parserClass();
        return $parser->parseFile($path)->getText();
    }
}
