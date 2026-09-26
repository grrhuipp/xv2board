<?php

namespace Tests\Unit;

use App\Logging\LogPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class LogPayloadSanitizerTest extends TestCase
{
    public function test_nested_app_secrets_are_redacted_and_safe_fields_remain(): void
    {
        $data = ['app_client_aes_key' => 'secret-value', 'app_client_aes_iv' => 'secret-iv',
            'payload' => ['auth_data' => 'private', 'password' => 'private', 'token' => 'private',
                'email' => 'user@example.test', 'code' => '123456']];
        $result = json_decode(LogPayloadSanitizer::encode($data), true);
        $this->assertSame('[REDACTED]', $result['app_client_aes_key']);
        $this->assertSame('[REDACTED]', $result['app_client_aes_iv']);
        $this->assertSame('[REDACTED]', $result['payload']['auth_data']);
        $this->assertSame('[REDACTED]', $result['payload']['password']);
        $this->assertSame('[REDACTED]', $result['payload']['token']);
        $this->assertSame('[REDACTED]', $result['payload']['code']);
        $this->assertSame('user@example.test', $result['payload']['email']);
    }

    public function test_oversized_payload_is_replaced_with_valid_bounded_json(): void
    {
        $encoded = LogPayloadSanitizer::encode(['body' => str_repeat('x', 70000)]);
        $this->assertLessThan(60000, strlen($encoded));
        $this->assertSame('log payload too large', json_decode($encoded, true)['omitted']);
    }
}
