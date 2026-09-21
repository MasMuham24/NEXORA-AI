<?php

namespace App\Services;

use App\Models\Document;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser;

class DocumentExtractionService
{
    public function extract(Document $document): string
    {
        $extension = strtolower(
            pathinfo($document->original_filename, PATHINFO_EXTENSION)
        );
        return match ($extension) {
            'txt' => $this->extractTxt($document),
            'docx' => $this->extractDocx($document),
            'pdf' => $this->extractPdf($document),
            default => throw new RuntimeException(
                "Unsupported document type: {$extension}"
            ),
        };
    }

    private function extractTxt(Document $document): string
    {
        if ($document->file_path === null || ! is_file($document->file_path)) {
            throw new RuntimeException('TXT file could not be found.');
        }
        $text = file_get_contents($document->file_path);
        if ($text === false || trim($text) === '') {
            throw new RuntimeException('TXT document contains no readable text.');
        }
        return trim($text);
    }

    private function extractDocx(Document $document): string
    {
        $this->ensureFileExists($document);
        $phpWord = IOFactory::load($document->file_path, 'Word2007');
        $paragraphs = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text = trim($element->getText());
                    if ($text !== '') {
                        $paragraphs[] = $text;
                    }
                }
            }
        }

        $text = trim(implode("\n", $paragraphs));
        if ($text === '') {
            throw new RuntimeException(
                'DOCX document contains no readable text.'
            );
        }
        return $text;
    }

    private function extractPdf(Document $document): string
    {
        $this->ensureFileExists($document);
        $parser = new Parser();
        $pdf = $parser->parseFile($document->file_path);
        $text = trim($pdf->getText());
        if ($text === '') {
            throw new RuntimeException(
                'PDF document contains no readable text.'
            );
        }
        return $text;
    }

    private function ensureFileExists(Document $document): void
    {
        if (
            $document->file_path === null ||
            ! is_file($document->file_path)
        ) {
            throw new RuntimeException('Document file could not be found.');
        }
    }
}
