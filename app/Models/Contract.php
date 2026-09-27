<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Module 6. Status moves are policed by the database (app.enforce_contract_
 * transition); an illegal one raises, whatever code path tries it.
 */
class Contract extends Model
{
    use HasUuidPrimaryKey;

    protected $table = 'contracts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'reference', 'request_id', 'property_id', 'owner_id', 'beneficiary_id',
        'lawyer_id', 'type', 'status', 'jurisdiction_id', 'value_amount', 'value_currency',
        'starts_on', 'ends_on', 'current_version_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value_amount' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function beneficiary()
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function versions()
    {
        return $this->hasMany(ContractVersion::class);
    }

    public function currentVersion()
    {
        return $this->belongsTo(ContractVersion::class, 'current_version_id');
    }

    /** The analysis of the version the contract currently points at, if any. */
    public function currentAnalysis()
    {
        return $this->hasOne(ContractAnalysis::class, 'contract_version_id', 'current_version_id');
    }

    public function aiJobs()
    {
        return $this->hasMany(AiJob::class);
    }

    /**
     * Not latestOfMany(): it tie-breaks with MAX(id), and Postgres has no
     * max() for uuid. Eager-loaded, a hasOne keeps the first row per contract.
     */
    public function latestAiJob()
    {
        return $this->hasOne(AiJob::class)->latest('queued_at');
    }

    public function isParty(User $user): bool
    {
        return in_array($user->id, [$this->owner_id, $this->beneficiary_id, $this->lawyer_id], true);
    }

    /**
     * value_amount is minor units and the exponent is per-currency (JOD is 3,
     * not 2). Integer arithmetic, so "450.000" never drifts to 449.999...
     */
    public function valueMajor(): string
    {
        $exponent = (int) DB::table('currencies')->where('code', $this->value_currency)->value('exponent');
        $unit = 10 ** $exponent;

        return $exponent === 0
            ? (string) $this->value_amount
            : sprintf('%d.%0'.$exponent.'d', intdiv($this->value_amount, $unit), $this->value_amount % $unit);
    }
}
