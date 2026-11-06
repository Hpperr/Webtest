<?php

declare(strict_types=1);

namespace TrangThu\Import;

use InvalidArgumentException;

final class ChapterSplitter
{
    private readonly int $wordLimit;

    public function __construct(int $wordLimit = 7000)
    {
        if ($wordLimit < 1) {
            throw new InvalidArgumentException('Chapter word limit must be greater than zero.');
        }
        $this->wordLimit = $wordLimit;
    }

    public function split(string $text): array
    {
        $blocks = preg_split('/\n{2,}/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chapters = [];
        $chapterNumber = 0;
        $title = '';
        $paragraphs = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if (preg_match('/^(?:chương|chapter|phần)\s+[\p{L}\p{N}IVX.-]+/iu', $block) === 1) {
                $this->flushChapter($chapters, $chapterNumber, $title, $paragraphs);
                $chapterNumber++;
                $title = mb_substr($block, 0, 240);
                $paragraphs = [];
                continue;
            }
            if ($chapterNumber === 0) {
                $chapterNumber = 1;
                $title = 'Chương 1';
            }
            $paragraphs[] = $block;
        }

        $this->flushChapter($chapters, $chapterNumber, $title, $paragraphs);
        return $chapters;
    }

    public function splitChapter(string $title, string $text, int $chapterNumber = 1): array
    {
        $paragraphs = preg_split('/\n{2,}/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chapters = [];
        $this->flushChapter($chapters, $chapterNumber, $title, $paragraphs);
        return $chapters;
    }

    private function flushChapter(array &$chapters, int $number, string $title, array $paragraphs): void
    {
        if ($number === 0 || $paragraphs === []) {
            return;
        }
        $parts = [];
        $current = [];
        $wordCount = 0;
        foreach ($paragraphs as $paragraph) {
            $words = $this->countWords($paragraph);
            if ($current !== [] && $wordCount + $words > $this->wordLimit) {
                $parts[] = ['content' => implode("\n\n", $current), 'word_count' => $wordCount];
                $current = [];
                $wordCount = 0;
            }
            $current[] = $paragraph;
            $wordCount += $words;
        }
        if ($current !== []) {
            $parts[] = ['content' => implode("\n\n", $current), 'word_count' => $wordCount];
        }

        foreach ($parts as $index => $part) {
            $partNumber = $index + 1;
            $chapters[] = [
                'chapter_number' => $number,
                'part_number' => $partNumber,
                'title' => $title,
                'content' => $part['content'],
                'word_count' => $part['word_count'],
            ];
        }
    }

    private function countWords(string $paragraph): int
    {
        $count = preg_match_all('/[\p{L}\p{N}]+/u', $paragraph, $matches);
        return $count === false ? 0 : $count;
    }
}
