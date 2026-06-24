<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectCostBudget extends Model
{
    use SoftDeletes, HasUserTracking;

    /**
     * Role tags written to transaction_lines.notes so the four disbursement
     * lines can be reliably identified again on edit / view / delete.
     */
    public const LINE_SOURCE      = 'صرف - المصدر (دائن)';
    public const LINE_ADMIN       = 'صرف - النسبة الإدارية (مدين)';
    public const LINE_TRANSFER    = 'صرف - نسبة التحويل (مدين)';
    public const LINE_DESTINATION = 'صرف - الوجهة (مدين - نهائي)';

    protected $fillable = [
        'project_cost_id',
        'transaction_id',
        'original_amount',
        'amount_after_deductions',
        'source_currency_id',
        'disbursement_currency_id',
        'administrative_percentage',
        'transfer_percentage',
        'exchange_percentage',
        'fx_rate',
        'final_amount',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'original_amount'           => 'decimal:2',
            'amount_after_deductions'   => 'decimal:2',
            'administrative_percentage' => 'decimal:2',
            'transfer_percentage'       => 'decimal:2',
            'exchange_percentage'       => 'decimal:2',
            'fx_rate'                   => 'decimal:6',
            'final_amount'              => 'decimal:2',
        ];
    }

    public function projectCost(): BelongsTo
    {
        return $this->belongsTo(ProjectCost::class);
    }

    public function sourceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'source_currency_id');
    }

    public function disbursementCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'disbursement_currency_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProjectCostBudgetsPayment::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
