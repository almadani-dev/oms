<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OMS Muwakha Families — reference data required by the feature.
 *
 * WHY A MIGRATION AND NOT A SEEDER. This repository had NO reference-data
 * shipping pattern before this task: `DatabaseSeeder` seeds only permissions,
 * settings and the bootstrap Super Admin, and every live `bank_types`,
 * `currencies`, `accounts_type` and `projects_super` row was entered by hand
 * through the Filament UI. A seeder would therefore require someone to
 * remember to run `db:seed` on every existing installation, which cannot be
 * assumed. A migration rides the normal deploy path exactly once per database
 * and is the only mechanism that satisfies both constraints. This migration
 * establishes that pattern for the repository.
 *
 * IDEMPOTENCY RULE, AND WHY IT CHECKS TRASHED ROWS TOO. Each value is
 * inserted only when NO row with that name exists — including a soft-deleted
 * one. Two reasons: it guarantees the "no duplicate names" requirement (a
 * trashed row plus a fresh row would be two rows with one name), and it never
 * resurrects a lookup an administrator deliberately deleted. Re-running this
 * migration on a database that already has the values is a no-op.
 *
 * NO HARD-CODED IDS anywhere: rows are matched and created by name only.
 */
return new class extends Migration
{
    /**
     * Missing bank/payment rails for Muwakha family accounts. `bank_types`
     * already holds بنك فلسطين / البنك الاسلامي الفلسطيني / USDT /
     * محفظة بال باي / محفظة جوال باي / كاش.
     */
    private const BANK_TYPES = [
        'بنك الإسكان',
        'بنك القدس',
        'فودافون كاش',
        'محفظة مؤقت',
    ];

    /**
     * The root under which every real Muwakha project lives. OMS has no
     * project-to-project parent relation — the only hierarchy is
     * `projects_super` (المشروع الرئيسي) -> `projects.project_super_id` — so
     * this root is a ProjectSuper, and a Project is eligible for family
     * linkage precisely when its `project_super_id` points here.
     *
     * A ProjectSuper is a different table from `projects`, so the root is
     * structurally incapable of appearing in any project Select. That is what
     * satisfies "the root project itself is not selectable as a family
     * project" — by construction rather than by a filter that could be
     * bypassed.
     */
    private const MUWAKHA_SUPER_NAME = 'مشروع المؤاخاة';

    private const MUWAKHA_SUPER_PREFIX = 'MUWAKHA';

    public function up(): void
    {
        $this->seedBankTypes();
        $this->seedMuwakhaProjectSuper();
    }

    /**
     * Intentionally irreversible (no-op).
     *
     * By the time a rollback could run, these rows may already be referenced
     * by real `accounts.bank_type_id` values and real
     * `projects.project_super_id` values. Deleting them would either violate
     * those foreign keys or orphan live financial records. Reference data
     * that the application has begun using is not something a schema
     * rollback may remove — that is a data decision requiring human review.
     */
    public function down(): void
    {
        // No-op by design — see the docblock above.
    }

    private function seedBankTypes(): void
    {
        $now = now();

        foreach (self::BANK_TYPES as $name) {
            $exists = DB::table('bank_types')->where('name', $name)->exists();

            if ($exists) {
                continue;
            }

            DB::table('bank_types')->insert([
                'name'       => $name,
                'notes'      => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * `code` replicates ProjectSuper's own `creating` hook verbatim
     * (uppercased prefix + '_' + zero-padded count-of-that-prefix + 1,
     * counting trashed rows) so a row created here is indistinguishable from
     * one created through the UI. The rule is reproduced rather than the
     * model being booted, because a migration must stay correct even if the
     * model changes later.
     */
    private function seedMuwakhaProjectSuper(): void
    {
        if (DB::table('projects_super')->where('name', self::MUWAKHA_SUPER_NAME)->exists()) {
            return;
        }

        $prefix = strtoupper(self::MUWAKHA_SUPER_PREFIX);

        $sequence = DB::table('projects_super')->where('code_prefix', $prefix)->count() + 1;

        $now = now();

        DB::table('projects_super')->insert([
            'name'        => self::MUWAKHA_SUPER_NAME,
            'code_prefix' => $prefix,
            'code'        => $prefix.'_'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'notes'       => 'المشروع الرئيسي لمشاريع المؤاخاة — تُربط أسر المؤاخاة بالمشاريع التابعة له.',
            'created_by'  => null,
            'updated_by'  => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
};
