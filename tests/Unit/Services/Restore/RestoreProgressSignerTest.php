<?php

namespace Tests\Unit\Services\Restore;

use App\Services\Restore\RestoreProgressSigner;
use Tests\TestCase;

class RestoreProgressSignerTest extends TestCase
{
    public function test_signing_is_deterministic_for_the_same_input(): void
    {
        $json = '{"a":1,"b":2}';

        $this->assertSame(RestoreProgressSigner::sign($json), RestoreProgressSigner::sign($json));
    }

    public function test_different_input_produces_a_different_signature(): void
    {
        $this->assertNotSame(
            RestoreProgressSigner::sign('{"a":1}'),
            RestoreProgressSigner::sign('{"a":2}'),
        );
    }

    public function test_verify_accepts_a_genuine_signature(): void
    {
        $json = '{"a":1,"b":2}';
        $signature = RestoreProgressSigner::sign($json);

        $this->assertTrue(RestoreProgressSigner::verify($json, $signature));
    }

    public function test_verify_rejects_a_tampered_payload(): void
    {
        $json = '{"a":1,"b":2}';
        $signature = RestoreProgressSigner::sign($json);

        $this->assertFalse(RestoreProgressSigner::verify('{"a":1,"b":3}', $signature));
    }

    public function test_verify_rejects_a_forged_signature(): void
    {
        $json = '{"a":1,"b":2}';

        $this->assertFalse(RestoreProgressSigner::verify($json, str_repeat('0', 64)));
    }

    public function test_signature_changes_when_app_key_changes(): void
    {
        $json = '{"a":1}';

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $first = RestoreProgressSigner::sign($json);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $second = RestoreProgressSigner::sign($json);

        $this->assertNotSame($first, $second);
    }
}
