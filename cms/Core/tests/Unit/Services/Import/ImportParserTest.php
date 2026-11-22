<?php

namespace Cms\Core\Tests\Unit\Services\Import;

use Cms\Core\Services\Import\ImportOptions;
use Cms\Core\Services\Import\Parser\CsvParser;
use Cms\Core\Services\Import\Parser\SpreadsheetParser;
use Cms\Core\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportParserTest extends TestCase
{
    public function test_csv_parser_steaming()
    {
        $content = "name,email\nJohn,john@example.com\nJane,jane@example.com";
        $path = sys_get_temp_dir() . '/test.csv';
        file_put_contents($path, $content);
        
        $file = new UploadedFile($path, 'test.csv', 'text/csv', null, true);
        
        $parser = new CsvParser();
        $options = new ImportOptions();
        
        $rows = [];
        foreach ($parser->rows($file, $options) as $row) {
            $rows[] = $row;
        }
        
        $this->assertCount(2, $rows);
        $this->assertEquals('John', $rows[0]['name']);
        $this->assertEquals('jane@example.com', $rows[1]['email']);
        
        unlink($path);
    }

    public function test_xlsx_parser_chunking()
    {
        if (!class_exists(Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet not installed');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'name');
        $sheet->setCellValue('B1', 'email');
        
        // Add 5 rows
        for ($i = 2; $i <= 6; $i++) {
            $sheet->setCellValue('A' . $i, 'Name ' . ($i - 1));
            $sheet->setCellValue('B' . $i, 'email' . ($i - 1) . '@test.com');
        }
        
        $path = sys_get_temp_dir() . '/test.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        
        $file = new UploadedFile($path, 'test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        
        $parser = new SpreadsheetParser();
        $options = new ImportOptions(chunk_size: 2); // Small chunk size to force multiple chunks
        
        $rows = [];
        foreach ($parser->rows($file, $options) as $row) {
            $rows[] = $row;
        }
        
        $this->assertCount(5, $rows);
        $this->assertEquals('Name 1', $rows[0]['name']);
        $this->assertEquals('email5@test.com', $rows[4]['email']);
        
        unlink($path);
    }
}
