<?php

namespace Tests\Unit;

use App\Support\PortalUrl;
use Tests\TestCase;

class PortalUrlTest extends TestCase
{
    public function test_it_builds_an_absolute_referee_link_from_the_student_portal_base(): void
    {
        config(['app.student_url' => 'http://localhost/office/student']);

        $url = PortalUrl::refereeInvite('AbC123');

        $this->assertSame('http://localhost/office/student/referee/AbC123', $url);
        $this->assertTrue(filter_var($url, FILTER_VALIDATE_URL) !== false);
    }

    public function test_it_resolves_a_relative_student_path_against_app_url(): void
    {
        config([
            'app.url' => 'https://office.example.edu',
            'app.student_url' => '/student',
        ]);

        $this->assertSame('https://office.example.edu/student', PortalUrl::studentBase());
        $this->assertSame(
            'https://office.example.edu/student/referee/tok',
            PortalUrl::refereeInvite('tok'),
        );
    }

    public function test_it_does_not_double_slashes_when_the_base_has_a_trailing_slash(): void
    {
        config(['app.student_url' => 'https://student.example.edu/']);

        $this->assertSame('https://student.example.edu/referee/tok', PortalUrl::refereeInvite('tok'));
    }
}
