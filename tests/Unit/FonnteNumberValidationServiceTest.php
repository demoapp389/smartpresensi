<?php

namespace Tests\Unit;

use App\Services\WhatsApp\FonnteNumberValidationService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FonnteNumberValidationServiceTest extends TestCase
{
    protected FonnteNumberValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('whatsapp.fonnte.token', 'test-fonnte-token');
        Config::set('whatsapp.fonnte.validate_url', 'https://api.fonnte.com/validate');
        $this->service = new FonnteNumberValidationService;
    }

    public function test_normalizes_various_indonesian_phone_formats(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalize('081234567890'));
        $this->assertEquals('6281234567890', $this->service->normalize('+6281234567890'));
        $this->assertEquals('6281234567890', $this->service->normalize('6281234567890'));
        $this->assertEquals('6281234567890', $this->service->normalize('81234567890'));
        $this->assertEquals('6281234567890', $this->service->normalize('0812-3456-7890'));
        $this->assertEquals('6281234567890', $this->service->normalize('0812 3456 7890'));
        $this->assertEquals('6281234567890', $this->service->normalize('(0812) 3456-7890'));
    }

    public function test_validates_indonesian_mobile_format(): void
    {
        $this->assertTrue($this->service->isValidIndonesianMobileFormat('6281234567890'));
        $this->assertTrue($this->service->isValidIndonesianMobileFormat('6285712345678'));
        $this->assertTrue($this->service->isValidIndonesianMobileFormat('628991234567'));

        // Invalid: too short
        $this->assertFalse($this->service->isValidIndonesianMobileFormat('6281234'));
        // Invalid: not starting with 628
        $this->assertFalse($this->service->isValidIndonesianMobileFormat('62217654321')); // Landline Jakarta
        $this->assertFalse($this->service->isValidIndonesianMobileFormat('1234567890'));
        // Invalid: too long
        $this->assertFalse($this->service->isValidIndonesianMobileFormat('628123456789012345'));
    }

    public function test_immediately_marks_invalid_format_without_calling_api(): void
    {
        Http::fake();

        $result = $this->service->validateSingle('12345');

        $this->assertEquals('invalid', $result['status']);
        $this->assertStringContainsString('Format nomor tidak valid', $result['error']);
        Http::assertNothingSent();
    }

    public function test_validates_registered_number_from_fonnte_api(): void
    {
        Http::fake([
            'https://api.fonnte.com/validate' => Http::response([
                'status' => true,
                'registered' => ['6281234567890'],
                'not_registered' => [],
            ], 200),
        ]);

        $result = $this->service->validateSingle('081234567890');

        $this->assertEquals('valid', $result['status']);
        $this->assertEquals('6281234567890', $result['normalized']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/validate'
                && $request['target'] === '6281234567890'
                && $request->hasHeader('Authorization', 'test-fonnte-token');
        });
    }

    public function test_validates_not_registered_number_from_fonnte_api(): void
    {
        Http::fake([
            'https://api.fonnte.com/validate' => Http::response([
                'status' => true,
                'registered' => [],
                'not_registered' => ['6281234567890'],
            ], 200),
        ]);

        $result = $this->service->validateSingle('081234567890');

        $this->assertEquals('invalid', $result['status']);
        $this->assertEquals('6281234567890', $result['normalized']);
        $this->assertStringContainsString('tidak terdaftar', $result['error']);
    }

    public function test_handles_api_failure_gracefully_as_unknown(): void
    {
        Http::fake([
            'https://api.fonnte.com/validate' => Http::response('Gateway Timeout', 504),
        ]);

        $result = $this->service->validateSingle('081234567890');

        $this->assertEquals('unknown', $result['status']);
        $this->assertEquals('6281234567890', $result['normalized']);
        $this->assertNotNull($result['error']);
    }

    public function test_batch_validation_parses_mixed_results(): void
    {
        Http::fake([
            'https://api.fonnte.com/validate' => Http::response([
                'status' => true,
                'registered' => ['6281234567891'],
                'not_registered' => ['6281234567892'],
            ], 200),
        ]);

        $phones = [
            '081234567891', // Will be registered (valid)
            '081234567892', // Will be not_registered (invalid)
            '08999',        // Format invalid (short) -> no API call
        ];

        $results = $this->service->validateBatch($phones);

        $this->assertEquals('valid', $results['081234567891']['status']);
        $this->assertEquals('invalid', $results['081234567892']['status']);
        $this->assertEquals('invalid', $results['08999']['status']);
    }
}
