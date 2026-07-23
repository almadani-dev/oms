<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupKeyRing;
use App\Services\Backup\Exceptions\BackupKeyConfigurationException;
use Tests\TestCase;

/**
 * Covers the OMS Task 7B.1 "CONFIG / PREREQUISITES" test category (items
 * 1-4): every key-configuration failure mode must fail closed, never fall
 * back to a default or unencrypted path.
 */
class BackupKeyRingTest extends TestCase
{
    private function validKeyBase64(): string
    {
        return base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    }

    // ---- 1. missing encryption key fails closed ------------------------------

    public function test_missing_key_id_fails_closed(): void
    {
        config(['oms.backup.encryption.key_id' => '', 'oms.backup.encryption.key' => $this->validKeyBase64()]);

        $this->expectException(BackupKeyConfigurationException::class);
        (new BackupKeyRing())->activeKey();
    }

    public function test_missing_key_value_fails_closed(): void
    {
        config(['oms.backup.encryption.key_id' => 'k1', 'oms.backup.encryption.key' => '']);

        $this->expectException(BackupKeyConfigurationException::class);
        (new BackupKeyRing())->activeKey();
    }

    // ---- 2. invalid base64 key fails closed -----------------------------------

    public function test_invalid_base64_key_fails_closed(): void
    {
        config(['oms.backup.encryption.key_id' => 'k1', 'oms.backup.encryption.key' => 'not-valid-base64-!!!']);

        $this->expectException(BackupKeyConfigurationException::class);
        (new BackupKeyRing())->activeKey();
    }

    // ---- 3. wrong key length fails closed --------------------------------------

    public function test_wrong_key_length_fails_closed(): void
    {
        config(['oms.backup.encryption.key_id' => 'k1', 'oms.backup.encryption.key' => base64_encode('too-short')]);

        $this->expectException(BackupKeyConfigurationException::class);
        (new BackupKeyRing())->activeKey();
    }

    public function test_valid_active_key_decodes_to_correct_length(): void
    {
        config(['oms.backup.encryption.key_id' => 'k1', 'oms.backup.encryption.key' => $this->validKeyBase64()]);

        $result = (new BackupKeyRing())->activeKey();

        $this->assertSame('k1', $result['key_id']);
        $this->assertSame(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, strlen($result['key']));
    }

    // ---- 4. unknown key id fails verification ----------------------------------

    public function test_unknown_key_id_fails_verification(): void
    {
        config([
            'oms.backup.encryption.key_id' => 'k1',
            'oms.backup.encryption.key' => $this->validKeyBase64(),
            'oms.backup.encryption.previous_keys' => [],
        ]);

        $this->expectException(BackupKeyConfigurationException::class);
        (new BackupKeyRing())->resolve('never-configured');
    }

    public function test_resolve_finds_previous_key_by_id(): void
    {
        $previousKey = base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));

        config([
            'oms.backup.encryption.key_id' => 'current',
            'oms.backup.encryption.key' => $this->validKeyBase64(),
            'oms.backup.encryption.previous_keys' => ['old' => $previousKey],
        ]);

        $resolved = (new BackupKeyRing())->resolve('old');

        $this->assertSame(base64_decode($previousKey, true), $resolved);
    }

    public function test_resolve_finds_active_key_by_id(): void
    {
        config(['oms.backup.encryption.key_id' => 'current', 'oms.backup.encryption.key' => $this->validKeyBase64()]);

        $resolved = (new BackupKeyRing())->resolve('current');

        $this->assertSame(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, strlen($resolved));
    }
}
