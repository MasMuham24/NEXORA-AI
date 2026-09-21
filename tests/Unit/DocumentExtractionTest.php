<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Services\DocumentExtractionService;
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

    public function test_txt_document_can_be_extracted(): void
    {
        $path = $this->tempDirectory.'/manual.txt';
        file_put_contents(
            $path,
            'NEXORA-AI is a document and knowledge management platform.'
        );
        $document = new Document([
            'original_filename' => 'manual.txt',
        ]);
        $document->file_path = $path;
        $service = new DocumentExtractionService;
        $result = $service->extract($document);
        $this->assertSame(
            'NEXORA-AI is a document and knowledge management platform.',
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
}
