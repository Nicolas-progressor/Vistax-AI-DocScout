<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentAnalysis extends Model
{
    use HasFactory;

    /**
     * Документ, к которому относится этот анализ
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Пресеты анализа
     */
    public const PRESET_LEGAL_AUDIT = 'legal_audit';
    public const PRESET_INVOICE_CHECK = 'invoice_check';
    public const PRESET_FREE_CHAT = 'free_chat';

    /**
     * Модели ИИ
     */
    public const MODEL_GEMMA2 = 'gemma2:2b';
    public const MODEL_LLAMA3 = 'llama3:8b';
}
