<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public static function secretKeys(): array
    {
        return array_map(fn ($key) => [$key], ['password', 'password_confirmation', 'current_password', 'password_hash', 'token', 'api_token', 'access_token', 'refresh_token', 'bearer_token', 'secret', 'client_secret', 'api_key', 'x-api-key', 'authorization', 'cookie', 'set-cookie', 'telegram_token', 'telegram_bot_token', 'bot_token', 'smtp_password', 'check_config', 'apiKey', 'authorization_header', 'PASSWORD', 'Authorization', 'remember_token', 'private_key']);
    }

    #[DataProvider('secretKeys')]
    public function test_secret_keys_are_redacted_from_entire_serialized_record(string $key): void
    {
        $secret = 'synthetic-secret-'.sha1($key);
        $values = ['safe' => 'Visible', 'nested' => [['deep' => [$key => $secret]]]];
        app(AuditLogger::class)->log('test.sanitizer', null, 'Sanitizer test', $values, $values, $values);
        $log = AuditLog::sole();
        $this->assertStringNotContainsString($secret, $log->toJson());
        $this->assertStringNotContainsString($secret, json_encode($log->getAttributes()));
        $this->assertStringContainsString('Visible', $log->toJson());
        $this->assertNull($log->actor_id);
    }

    public function test_all_nested_structures_and_safe_markers(): void
    {
        $payload = ['headers' => ['Authorization' => 'Bearer synthetic-one', 'X-API-Key' => 'synthetic-two', 'Cookie' => 'synthetic-three'], 'body' => ['api_token' => 'synthetic-four', 'user' => ['password' => 'synthetic-five']], 'credentials' => ['client_secret' => 'synthetic-six'], 'nested' => ['auth' => ['bearer_token' => 'synthetic-seven']], 'deep' => ['level' => ['telegram_token' => 'synthetic-eight']], 'password_changed' => true, 'telegram_token_action' => 'replaced'];
        app(AuditLogger::class)->log('test.nested', null, 'Nested data', $payload, $payload, $payload);
        $serialized = AuditLog::sole()->toJson();
        foreach (['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'] as $suffix) {
            $this->assertStringNotContainsString('synthetic-'.$suffix, $serialized);
        }
        $this->assertTrue(AuditLog::sole()->context['password_changed']);
        $this->assertSame('replaced', AuditLog::sole()->context['telegram_token_action']);
    }
}
