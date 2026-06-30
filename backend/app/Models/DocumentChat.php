<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChat extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'role',
        'content',
        'message_order',
    ];

    protected $casts = [
        'message_order' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
