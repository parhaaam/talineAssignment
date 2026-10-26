<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        return $this->getJson('/docs/api.json')->assertOk()->json();
    }

    public function test_the_interactive_docs_and_the_spec_are_served(): void
    {
        $this->get('/docs/api')->assertOk()->assertSee('Wallet &amp; Ledger API', false);

        $spec = $this->spec();
        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertSame('Wallet & Ledger API', $spec['info']['title']);
        $this->assertEqualsCanonicalizing(
            ['/transfers', '/deposits', '/deposits/callback/{provider}', '/wallets/{wallet}/transactions'],
            array_keys($spec['paths']),
        );
    }

    public function test_docs_are_hidden_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_post_operations_document_headers_status_and_a_clean_body(): void
    {
        $spec = $this->spec();

        foreach (['/transfers', '/deposits'] as $path) {
            $operation = $spec['paths'][$path]['post'];
            $headers = collect($operation['parameters'])->where('in', 'header')->pluck('name')->all();

            $this->assertEqualsCanonicalizing(['X-User-Id', 'Idempotency-Key'], $headers, $path);
            $this->assertArrayHasKey('201', $operation['responses'], $path);
            $this->assertArrayNotHasKey('200', $operation['responses'], $path);
        }

        $body = $spec['components']['schemas']['CreateTransferRequest']['properties'];
        $this->assertSame(['from_wallet_id', 'to_wallet_id', 'amount'], array_keys($body));
    }

    public function test_domain_errors_are_documented_with_the_apis_error_shape_grouped_by_status(): void
    {
        $responses = $this->spec()['paths']['/transfers']['post']['responses'];

        $this->assertEqualsCanonicalizing(['201', '401', '403', '404', '409', '422'], array_keys($responses));

        $codes = $responses['422']['content']['application/json']['schema']['properties']['error']['properties']['code']['enum'];
        $this->assertEqualsCanonicalizing(
            ['insufficient_funds', 'self_transfer', 'currency_mismatch', 'currency_inactive', 'invalid_amount', 'validation_failed'],
            $codes,
        );
        $this->assertArrayHasKey('fields', $responses['422']['content']['application/json']['schema']['properties']['error']['properties']);
    }

    public function test_the_provider_callback_does_not_require_the_user_header(): void
    {
        $parameters = collect($this->spec()['paths']['/deposits/callback/{provider}']['get']['parameters'])->pluck('name')->all();

        $this->assertSame(['provider'], $parameters);
    }

    public function test_the_committed_openapi_file_matches_the_controllers(): void
    {
        $committed = json_decode((string) file_get_contents(base_path('api.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = sys_get_temp_dir().'/openapi-'.bin2hex(random_bytes(4)).'.json';

        Artisan::call('scramble:export', ['--path' => $path]);
        $generated = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);

        $this->assertEquals(
            ['paths' => $committed['paths'], 'components' => $committed['components']],
            ['paths' => $generated['paths'], 'components' => $generated['components']],
            'api.json is stale: run `php artisan scramble:export` and commit the result.',
        );
    }
}
