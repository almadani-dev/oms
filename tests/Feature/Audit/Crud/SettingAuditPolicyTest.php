<?php

namespace Tests\Feature\Audit\Crud;

use App\Models\AuditEvent;
use App\Models\Setting;
use App\Services\Audit\AuditPayloadBounder;
use App\Services\Audit\AuditRedactor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * OMS Task 9B.2 §8 — the strict safe-value policy for the free-form
 * `settings` table, plus the payload-bounding guarantees the shared
 * component inherits from the 9B.1 foundation.
 */
class SettingAuditPolicyTest extends AuditedCrudTestCase
{
    public function test_an_ordinary_setting_value_is_stored(): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new Setting, [
            'key' => 'timezone',
            'value' => 'Asia/Hebron',
            'group' => 'general',
            'description' => 'Application timezone',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('setting', $event->subject_type);
        $this->assertSame('Asia/Hebron', $event->new_values['value']);
        $this->assertSame('general', $event->new_values['group']);
        $this->assertSame('Application timezone', $event->new_values['description']);
    }

    /**
     * `settings.key` is emitted under the safe semantic name `setting_name`,
     * so it survives AuditRedactor's (correct, global) treatment of a bare
     * `key` field as secret-shaped. The raw column name never reaches the
     * payload at all.
     */
    public function test_the_setting_key_is_recorded_as_a_semantic_setting_name(): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new Setting, [
            'key' => 'organization_name',
            'value' => 'مؤسسة',
            'group' => 'general',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame('organization_name', $event->new_values['setting_name']);
        $this->assertArrayNotHasKey('key', $event->new_values);
        $this->assertSame('organization_name (general)', $event->subject_label);
    }

    public function test_renaming_a_setting_key_records_both_the_old_and_the_new_name(): void
    {
        $this->actingAsSuperAdmin();

        $setting = Setting::create([
            'key' => 'old_setting_name',
            'value' => 'Asia/Hebron',
            'group' => 'general',
        ]);

        $this->service()->update($setting, [
            'key' => 'new_setting_name',
            'value' => 'Asia/Hebron',
            'group' => 'general',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame(['setting_name'], $event->changed_fields);
        $this->assertSame(['setting_name' => 'old_setting_name'], $event->old_values);
        $this->assertSame(['setting_name' => 'new_setting_name'], $event->new_values);
        $this->assertArrayNotHasKey('key', $event->old_values);
        $this->assertArrayNotHasKey('key', $event->new_values);
    }

    public function test_deleting_a_setting_preserves_its_name_in_the_snapshot(): void
    {
        $this->actingAsSuperAdmin();

        $setting = Setting::create([
            'key' => 'date_format',
            'value' => 'Y-m-d',
            'group' => 'general',
            'description' => 'Default date display format',
        ]);

        $this->service()->delete($setting);

        $event = AuditEvent::sole();

        $this->assertSame('deleted', $event->event_action);
        $this->assertSame('date_format', $event->old_values['setting_name']);
        $this->assertSame('Y-m-d', $event->old_values['value']);
        $this->assertArrayNotHasKey('key', $event->old_values);
        $this->assertNull($event->new_values);
    }

    /**
     * The semantic rename is a payload-key change only — it must not become
     * a way for key-material to survive redaction.
     */
    public function test_a_secret_shaped_name_is_still_recorded_while_its_value_is_not(): void
    {
        $this->actingAsSuperAdmin();

        $setting = Setting::create([
            'key' => 'smtp_password',
            'value' => 'first-secret-value',
            'group' => 'mail',
        ]);

        $this->service()->update($setting, [
            'key' => 'mail.password',
            'value' => 'second-secret-value',
            'group' => 'mail',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame(['setting_name', 'value'], $event->changed_fields);
        $this->assertSame('smtp_password', $event->old_values['setting_name']);
        $this->assertSame('mail.password', $event->new_values['setting_name']);
        $this->assertSame(AuditRedactor::MARKER, $event->old_values['value']);
        $this->assertSame(AuditRedactor::MARKER, $event->new_values['value']);

        $encoded = json_encode([$event->old_values, $event->new_values], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('first-secret-value', $encoded);
        $this->assertStringNotContainsString('second-secret-value', $encoded);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secretShapedKeys(): array
    {
        return [
            'underscored password' => ['smtp_password', 'hunter2-not-really'],
            'dotted password' => ['mail.password', 'hunter2-not-really'],
            'dashed secret' => ['stripe-secret', 'sk_live_plaintext'],
            'api token' => ['api_token', 'plaintext-token'],
            'encryption key' => ['backup_encryption_key', 'plaintext-key'],
            'credentials' => ['db_credentials', 'user:pass'],
        ];
    }

    #[DataProvider('secretShapedKeys')]
    public function test_a_secret_shaped_setting_key_never_stores_its_value(string $key, string $value): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new Setting, ['key' => $key, 'value' => $value, 'group' => 'system']);

        $event = AuditEvent::sole();

        $this->assertSame(AuditRedactor::MARKER, $event->new_values['value']);
        $this->assertStringNotContainsString($value, json_encode($event->new_values, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_credential_shaped_value_is_redacted_even_under_an_innocent_key(): void
    {
        $this->actingAsSuperAdmin();

        $opaque = str_repeat('A1b2C3d4', 8); // 64 opaque characters, no whitespace

        $this->service()->create(new Setting, ['key' => 'integration_handle', 'value' => $opaque, 'group' => 'system']);

        $event = AuditEvent::sole();

        $this->assertSame(AuditRedactor::MARKER, $event->new_values['value']);
    }

    public function test_a_pem_block_is_redacted(): void
    {
        $this->actingAsSuperAdmin();

        $this->service()->create(new Setting, [
            'key' => 'signing_material',
            'value' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
            'group' => 'system',
        ]);

        $this->assertSame(AuditRedactor::MARKER, AuditEvent::sole()->new_values['value']);
    }

    public function test_updating_a_setting_records_only_the_changed_field(): void
    {
        $this->actingAsSuperAdmin();

        $setting = Setting::create([
            'key' => 'date_format',
            'value' => 'Y-m-d',
            'group' => 'general',
            'description' => 'Default date display format',
        ]);

        $this->service()->update($setting, [
            'key' => 'date_format',
            'value' => 'd/m/Y',
            'group' => 'general',
            'description' => 'Default date display format',
        ]);

        $event = AuditEvent::sole();

        $this->assertSame(['value'], $event->changed_fields);
        $this->assertSame(['value' => 'Y-m-d'], $event->old_values);
        $this->assertSame(['value' => 'd/m/Y'], $event->new_values);
    }

    public function test_deleting_a_secret_shaped_setting_does_not_leak_its_value_into_the_snapshot(): void
    {
        $this->actingAsSuperAdmin();

        $setting = Setting::create(['key' => 'smtp_password', 'value' => 'super-secret-value', 'group' => 'mail']);

        $this->service()->delete($setting);

        $event = AuditEvent::sole();

        $this->assertSame('deleted', $event->event_action);
        $this->assertSame(AuditRedactor::MARKER, $event->old_values['value']);
        $this->assertStringNotContainsString('super-secret-value', json_encode($event->old_values, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_long_setting_value_stays_within_the_foundation_limits(): void
    {
        $this->actingAsSuperAdmin();

        // Whitespace-separated so the credential-shape rule does not apply —
        // this is prose-like content being length-bounded, not redacted.
        $long = trim(str_repeat('قيمة طويلة ', 500));

        $this->service()->create(new Setting, ['key' => 'welcome_text', 'value' => $long, 'group' => 'general']);

        $event = AuditEvent::sole();

        $this->assertSame(1000, mb_strlen($event->new_values['value']));
        $this->assertLessThanOrEqual(
            8192,
            strlen((string) json_encode($event->new_values, JSON_UNESCAPED_UNICODE)),
        );
        $this->assertArrayNotHasKey(AuditPayloadBounder::TRUNCATED_MARKER_KEY, $event->new_values);
    }
}
