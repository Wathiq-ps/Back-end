<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Keyed on user_id, not its own id — a user has at most one set of
 * credentials, and a re-submission after rejection overwrites the row rather
 * than adding another (unlike app.identity_documents, which keeps a history).
 */
class LawyerCredential extends Model
{
    protected $table = 'lawyer_credentials';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'license_number', 'bar_association', 'jurisdiction_id',
        'issued_at', 'expires_at', 'document_path', 'status',
        'verified_at', 'verified_by', 'reviewed_at', 'reviewed_by', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
