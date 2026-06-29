<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Document extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_name',
        'file_hash',
        'file_path',
        'raw_text',
    ];

    /**
     * Анализы, связанные с этим документом
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(DocumentAnalysis::class);
    }

    /**
     * Получить последний анализ для определённого пресета
     */
    public function latestAnalysisForPreset(string $preset): ?DocumentAnalysis
    {
        return $this->analyses()
            ->where('preset', $preset)
            ->latest()
            ->first();
    }
}
