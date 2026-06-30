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
        
        // Определение расширения и детекция маршрута парсинга
        $extension = strtolower($file->getClientOriginalExtension());
        
        // Извлечение текста в зависимости от типа файла
        $rawText = match ($extension) {
            'txt' => $this->parseTxt($file),
            'json' => $this->parseJson($file),
            'pdf' => $this->parsePdf($file),
            'docx' => $this->parseDocx($file),
            'doc' => $this->parseDoc($file),
            default => throw new \InvalidArgumentException(
                "Неподдерживаемый тип файла: {$extension}. Поддерживаются: txt, json, pdf, docx, doc"
            ),
        };
        
        // Валидация результата
        if (trim($rawText) === '') {
            throw new \Exception("Не удалось извлечь текст из файла данного формата ({$extension})");
        }
        
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
     * Парсинг DOCX файлов (нативный PHP через ZipArchive)
     * .docx — это ZIP-архив с XML внутри (word/document.xml)
     */
    private function parseDocx(UploadedFile $file): string
    {
        $zip = new \ZipArchive();
        $rawText = '';
        
        if ($zip->open($file->getRealPath()) === true) {
            // Ищем основной XML документ в архиве
            $index = $zip->locateName('word/document.xml');
            
            if ($index !== false) {
                $xmlContent = $zip->getFromIndex($index);
                
                if ($xmlContent !== false) {
                    // Заменяем закрывающие теги абзацев на переносы строк
                    // Затем удаляем все XML-теги, оставляя чистый текст
                    $rawText = strip_tags(str_replace('</w:p>', "\n", $xmlContent));
                    
                    // Очищаем от лишних пробелов и символов
                    $rawText = preg_replace('/\s+/u', ' ', trim($rawText));
                }
            }
            
            $zip->close();
        }
        
        if ($rawText === '') {
            throw new \Exception("Не удалось извлечь текст из DOCX файла: повреждённая структура архива");
        }
        
        return $rawText;
    }
    
    /**
     * Парсинг DOC файлов (бинарный формат, текстовый стриппинг)
     * Для старых .doc без тяжелых зависимостей (LibreOffice)
     * Метод: извлечение читаемых ASCII/UTF-8 символов через regex
     */
    private function parseDoc(UploadedFile $file): string
    {
        $fileContent = file_get_contents($file->getRealPath());
        
        if ($fileContent === false) {
            throw new \Exception("Не удалось прочитать содержимое DOC файла");
        }
        
        // Извлекаем последовательности печатных символов:
        // - Латиница (a-zA-Z)
        // - Кириллица (а-яА-Я, включая ёЁ)
        // - Цифры (0-9)
        // - Базовая пунктуация и спецсимволы
        // - Пробельные символы
        preg_match_all('/[a-zA-Zа-яА-ЯёЁ0-9\s\.,;:!?\(\)\-\"\'«»№—\/\\\\@#$%&*+=_<>\[\]{}|]+/u', $fileContent, $matches);
        
        // Объединяем все найденные фрагменты
        $rawText = implode(' ', $matches[0]);
        
        // Очищаем от множественных пробелов
        $rawText = preg_replace('/\s+/u', ' ', trim($rawText));
        
        if ($rawText === '') {
            throw new \Exception("Не удалось извлечь текст из DOC файла: файл пуст или содержит только бинарные данные");
        }
        
        return $rawText;
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
