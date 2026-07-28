<?php

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditRedactor;
use Tests\TestCase;

class AuditRedactorTest extends TestCase
{
    private AuditRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new AuditRedactor;
    }

    public function test_explicit_secret_fields_are_redacted(): void
    {
        $result = $this->redactor->redact([
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'remember_token' => 'abc123',
            'api_token' => 'abc123',
            'access_token' => 'abc123',
            'refresh_token' => 'abc123',
            'bearer_token' => 'abc123',
            'session_id' => 'abc123',
            'csrf_token' => 'abc123',
            'app_key' => 'base64:abc123',
            'db_password' => 'abc123',
            'encryption_key' => 'abc123',
            'backup_encryption_key' => 'abc123',
            'previous_keys' => ['k1' => 'abc', 'k2' => 'def'],
            'name' => 'Ahmad',
        ]);

        foreach ([
            'password', 'password_confirmation', 'remember_token', 'api_token',
            'access_token', 'refresh_token', 'bearer_token', 'session_id',
            'csrf_token', 'app_key', 'db_password', 'encryption_key',
            'backup_encryption_key', 'previous_keys',
        ] as $field) {
            $this->assertSame(AuditRedactor::MARKER, $result[$field], "{$field} should be redacted");
        }

        $this->assertSame('Ahmad', $result['name']);
    }

    public function test_nested_secrets_are_redacted(): void
    {
        // 'security' is deliberately a non-sensitive container key here so
        // this test proves per-leaf recursion — a container key that is
        // ITSELF sensitive (e.g. 'credentials') is expected to have its
        // whole subtree redacted at that level instead; see
        // test_a_sensitive_container_key_redacts_its_whole_subtree().
        $result = $this->redactor->redact([
            'user' => [
                'name' => 'Ahmad',
                'security' => [
                    'password' => 'super-secret',
                    'api_token' => 'abc123',
                ],
            ],
        ]);

        $this->assertSame('Ahmad', $result['user']['name']);
        $this->assertSame(AuditRedactor::MARKER, $result['user']['security']['password']);
        $this->assertSame(AuditRedactor::MARKER, $result['user']['security']['api_token']);
    }

    public function test_a_sensitive_container_key_redacts_its_whole_subtree(): void
    {
        $result = $this->redactor->redact([
            'user' => [
                'name' => 'Ahmad',
                'credentials' => [
                    'password' => 'super-secret',
                    'api_token' => 'abc123',
                ],
            ],
        ]);

        $this->assertSame('Ahmad', $result['user']['name']);
        $this->assertSame(AuditRedactor::MARKER, $result['user']['credentials']);
    }

    public function test_pattern_based_fail_closed_denial(): void
    {
        $result = $this->redactor->redact([
            'oauth_secret' => 'x',
            'session_token' => 'x',
            'account_key' => 'x',
            'db_credentials' => 'x',
            'authorization' => 'Bearer abc',
            'cookie' => 'session=abc',
        ]);

        foreach (['oauth_secret', 'session_token', 'account_key', 'db_credentials', 'authorization', 'cookie'] as $field) {
            $this->assertSame(AuditRedactor::MARKER, $result[$field], "{$field} should be redacted");
        }
    }

    public function test_encryption_key_id_safe_exception_is_preserved(): void
    {
        $result = $this->redactor->redact([
            'encryption_key_id' => 'oms-key-2026-07',
        ]);

        $this->assertSame('oms-key-2026-07', $result['encryption_key_id']);
    }

    public function test_ordinary_non_secret_values_are_preserved(): void
    {
        $result = $this->redactor->redact([
            'account_id' => 12,
            'account_code' => 'ACC-001',
            'bank_type_id' => 3,
            'amount' => '1500.00',
            'currency_code' => 'USD',
            'is_protected' => false,
            'notes' => 'دفعة تنفيذ للمستفيد',
        ]);

        $this->assertSame(12, $result['account_id']);
        $this->assertSame('ACC-001', $result['account_code']);
        $this->assertSame(3, $result['bank_type_id']);
        $this->assertSame('1500.00', $result['amount']);
        $this->assertSame('USD', $result['currency_code']);
        $this->assertFalse($result['is_protected']);
        $this->assertSame('دفعة تنفيذ للمستفيد', $result['notes']);
    }

    public function test_resource_and_closure_values_are_never_persisted_raw(): void
    {
        $resource = fopen('php://memory', 'r');

        $result = $this->redactor->redact([
            'file_handle' => $resource,
            'callback' => static fn () => 'x',
        ]);

        $this->assertSame('[UNSUPPORTED_VALUE]', $result['file_handle']);
        $this->assertSame('[UNSUPPORTED_VALUE]', $result['callback']);

        fclose($resource);
    }
}
