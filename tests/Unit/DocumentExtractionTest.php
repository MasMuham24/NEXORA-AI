<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Services\DocumentExtractionService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDirectory = storage_path('framework/testing/documents');
        if (! is_dir($this->tempDirectory)) {
            mkdir($this->tempDirectory, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_docx_document_can_be_extracted(): void
    {
        $path = $this->tempDirectory.'/manual.docx';
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('NEXORA-AI Document Knowledge Management.');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $document = new Document([
            'original_filename' => 'manual.docx',
        ]);
        $document->file_path = $path;
        $service = new DocumentExtractionService;
        $result = $service->extract($document);
        $this->assertStringContainsString(
            'NEXORA-AI Document Knowledge Management.',
            $result
        );
    }

    public function test_unsupported_document_type_throws_exception(): void
    {
        $document = new Document([
            'original_filename' => 'manual.exe',
            'file_path' => $this->tempDirectory.'/manual.exe',
        ]);
        $service = new DocumentExtractionService;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported document type: exe');
        $service->extract($document);
    }

    public function test_missing_document_file_throws_exception(): void
    {
        $document = new Document([
            'original_filename' => 'missing.txt',
            'file_path' => $this->tempDirectory.'/missing.txt',
        ]);
        $service = new DocumentExtractionService;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TXT file could not be found.');
        $service->extract($document);
    }

    public function test_pdf_document_can_be_extracted(): void
    {
        $path = $this->tempDirectory.'/manual.pdf';
        file_put_contents($path, $this->buildMinimalPdf(
            'NEXORA-AI Knowledge Platform'
        ));
        $document = new Document([
            'original_filename' => 'manual.pdf',
        ]);
        $document->file_path = $path;
        $service = new DocumentExtractionService;
        $result = $service->extract($document);
        $this->assertStringContainsString(
            'NEXORA-AI Knowledge Platform',
            $result
        );
    }

    private function buildMinimalPdf(string $text): string
    {
        $stream = "BT\n/F1 18 Tf\n100 700 Td\n({$text}) Tj\nET\n";
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            4 => "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream\nendobj\n",
            5 => "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $content = "%PDF-1.4\n";
        $offsets = [];
        foreach ([1, 2, 3, 4, 5] as $num) {
            $offsets[$num] = strlen($content);
            $content .= $objects[$num];
        }

        $xrefStart = strlen($content);
        $xref = "xref\n0 6\n";
        $xref .= sprintf("%010d 65535 f\r\n", 0);
        foreach ([1, 2, 3, 4, 5] as $num) {
            $xref .= sprintf("%010d 00000 n\r\n", $offsets[$num]);
        }

        return $content.$xref."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";
    }
}
