<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OMS Muwakha Families — the family <-> Muwakha project link.
 *
 * A first-class table rather than a plain pivot, because the relation carries
 * its own business field (`card_code`, the family's card number WITHIN one
 * specific Muwakha project) and its own validation and audit requirements.
 *
 * A family may have zero, one, or many simultaneous project links.
 *
 * FOREIGN KEY NAMING: the brief listed this column as `family_id`, but every
 * foreign key in this repository is named from its full model
 * (`project_cost_budget_id`, `transaction_super_type_id`, `project_super_id`),
 * and the brief also directs the table to follow repository naming
 * conventions. `muwakha_family_id` is used so this is not the single
 * truncated foreign key in the schema and so Laravel resolves the
 * relationship without an explicit override.
 *
 * NO SoftDeletes: removing a family from a project is a genuine unlink, not
 * an archivable record. Links are hard-deleted, and `cascadeOnDelete` on both
 * sides is a safety net for a hard delete of either parent — it never fires
 * for the ordinary family soft delete, which is why MuwakhaFamilyService
 * deletes the links explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('muwakha_family_projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('muwakha_family_id')->constrained('muwakha_families')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // Optional card number, e.g. "G 10". Scoped to the project.
            $table->string('card_code', 100)->nullable();

            $table->timestamps();

            // The same family can never be linked twice to the same project.
            $table->unique(
                ['muwakha_family_id', 'project_id'],
                'muwakha_family_projects_family_project_unique',
            );

            // A card code, when present, is unique WITHIN one project. MySQL
            // treats NULLs as distinct in a unique index, so any number of
            // families in the same project may have no card code at all —
            // which is exactly the approved rule.
            $table->unique(
                ['project_id', 'card_code'],
                'muwakha_family_projects_project_card_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('muwakha_family_projects');
    }
};
