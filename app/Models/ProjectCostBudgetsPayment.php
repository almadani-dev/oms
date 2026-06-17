<?php

namespace App\Models;

use App\Traits\HasUserTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectCostBudgetsPayment extends Model
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

    /**
     * Role tags for the two execution-payment (صرف مبالغ التنفيذ) lines, so the
     * beneficiary (debit) and auto credit (destination) lines can be reliably
     * identified again on edit / view / delete.
     */
    public const LINE_BENEFICIARY = 'تنفيذ - المستفيد (مدين)';
    public const LINE_CREDIT      = 'تنفيذ - الوجهة التلقائية (دائن)';

    protected $fillable = [
        'project_cost_budget_id',
        'transaction_id',
        'amount',
        'date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date'   => 'date',
        ];
    }

    public function projectCostBudget(): BelongsTo
    {
        return $this->belongsTo(ProjectCostBudget::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
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
