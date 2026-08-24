<?php

namespace Tests\Unit;

use App\Support\ApplicantPassport;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantPassportTest extends TestCase
{
    public function test_it_embeds_a_public_disk_photo_as_a_data_uri(): void
    {
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        Storage::disk('public')->put('nin-photos/1/12345678901.png', $png);

        $uri = ApplicantPassport::dataUri('nin-photos/1/12345678901.png');

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/', $uri);
    }

    public function test_it_returns_an_existing_data_uri_unchanged(): void
    {
        $uri = 'data:image/jpeg;base64,/9j/4AAQ';

        $this->assertSame($uri, ApplicantPassport::dataUri($uri));
    }
}
