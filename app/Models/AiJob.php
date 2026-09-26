<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One request to the AI service and its outcome. The id is the job_id on the
 * wire. A succeeded row must carry provenance and a failed one an error_code
 * — the table's check constraints refuse it otherwise (NFR-12.3).
 */
class AiJob extends Model
{
    use HasUuidPrimaryKey;

    /** Statuses in which a callback (or a timeout) can still change the row. */
    public const IN_FLIGHT = ['queued', 'dispatched', 'running'];

    public $timestamps = false;

    protected $table = 'ai_jobs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'contract_id', 'contract_version_id', 'kind', 'status', 'requested_by',
        'provider', 'model_id', 'model_version', 'prompt_version', 'kb_version_id', 'input_hash',
        'latency_ms', 'tokens_input', 'tokens_output', 'attempts', 'error_code', 'error_message', 'result',
        'dispatched_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'queued_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractVersion()
    {
        return $this->belongsTo(ContractVersion::class);
    }
}
