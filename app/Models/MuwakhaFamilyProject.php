<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a Muwakha family and one Muwakha project.
 *
 * A first-class model rather than a plain pivot, because the relation carries
 * its own business field (`card_code` — the family's card number within that
 * one project) plus its own validation and audit requirements.
 *
 * Deliberately NOT SoftDeletes: removing a family from a project is a real
 * unlink, not an archivable record. There is also no user tracking here — the
 * accountable actor for a link change lives on the link's own AuditEvent,
 * which carries both sides, and adding created_by/updated_by columns would
 * duplicate that with no reader.
 */
class MuwakhaFamilyProject extends Model
{
    protected $table = 'muwakha_family_projects';

    protected $fillable = [
        'muwakha_family_id',
        'project_id',
        'card_code',
    ];

    public function muwakhaFamily(): BelongsTo
    {
        return $this->belongsTo(MuwakhaFamily::class, 'muwakha_family_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * "مؤاخاة كاف 2026 — G 10", or just the project name when the family has
     * no card code in that project. Used by the families table column and by
     * both exports, so the two can never drift apart.
     */
    public function displayLabel(): string
    {
        $projectName = (string) ($this->project?->name ?? '');

        $cardCode = trim((string) ($this->card_code ?? ''));

        return $cardCode === ''
            ? $projectName
            : trim($projectName.' — '.$cardCode);
    }
}
