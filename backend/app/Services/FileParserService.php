<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FileParserService
{
    /**
     * Сохранить файл и извлечь текст
     *
     * @return array{file_path: string, raw_text: string, file_hash: string}
     */
    public function parse(UploadedFile $file): array
    {
        // Расчёт SHA-256 хэша
        $fileHash = hash_file('sha256', $file->getRealPath());
        
        // Сохранение файла
        $filePath = $file->store('documents', ['disk' => 'local']);
        
        // Извлечение текста в зависимости от типа файла
        $rawText = match ($file->getClientOriginalExtension()) {
            'txt' => $this->parseTxt($file),
            'json' => $this->parseJson($file),
            'pdf' => $this->parsePdf($file),
            default => throw new \InvalidArgumentException('Неподдерживаемый тип файла'),
        };
        
        return [
            'file_path' => $filePath,
            'raw_text' => $rawText,
            'file_hash' => $fileHash,
        ];
    }
    
    /**
     * Парсинг TXT файлов
     */
    private function parseTxt(UploadedFile $file): string
    {
        return file_get_contents($file->getRealPath());
    }
    
    /**
     * Парсинг JSON файлов
     */
    private function parseJson(UploadedFile $file): string
    {
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Невалидный JSON');
        }
        
        return $this->flattenArray($data);
    }
    
    /**
     * Парсинг PDF файлов
     * Приоритет: pdftotext (poppler) → smalot/pdfparser (PHP)
     */
    private function parsePdf(UploadedFile $file): string
    {
        // Метод 1: pdftotext (быстрый, системный)
        if (function_exists('shell_exec')) {
            $tempFile = tempnam(sys_get_temp_dir(), 'pdf_');
            shell_exec("pdftotext -layout -enc UTF-8 " . escapeshellarg($file->getRealPath()) . " " . escapeshellarg($tempFile));
            
            if (file_exists($tempFile)) {
                $text = file_get_contents($tempFile);
                unlink($tempFile);
                
                if (trim($text) !== '') {
                    return $text;
                }
            }
        }
        
        // Метод 2: smalot/pdfparser (медленный, PHP)
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file->getRealPath());
                return $pdf->getText();
            } catch (\Exception $e) {
                // Fallback
            }
        }
        
        throw new \RuntimeException(
            'Не удалось распарсить PDF. Установите poppler-utils или smalot/pdfparser'
        );
    }
    
    /**
     * Рекурсивное превращение массива в строку
     */
    private function flattenArray(array $data, int $depth = 0): string
    {
        $result = '';
        $indent = str_repeat('  ', $depth);
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result .= "{$indent}{$key}:\n" . $this->flattenArray($value, $depth + 1);
            } else {
                $result .= "{$indent}{$key}: {$value}\n";
            }
        }
        
        return $result;
    }
}
