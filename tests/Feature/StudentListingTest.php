<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class StudentListingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        foreach (range(1, 30) as $index) {
            Student::create([
                'nis' => '1000'.$index,
                'name' => 'Siswa '.$index,
                'gender' => 'L',
                'active' => true,
            ]);
        }
    }

    public function test_student_listing_uses_server_side_pagination(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.index'));

        $response->assertOk();
        $response->assertViewHas('students', fn ($students) => $students instanceof LengthAwarePaginator);
        $response->assertViewHas('students', fn ($students) => $students->count() === 10 && $students->total() === 30);

        $html = $response->getContent();
        $this->assertStringNotContainsString('data-datatable', $html);
        $this->assertStringContainsString('Menampilkan', $html);
        $this->assertStringContainsString('name="per_page"', $html);
    }

    public function test_student_listing_keeps_search_filter(): void
    {
        Student::create([
            'nis' => '9999',
            'name' => 'Siswa Filtered',
            'gender' => 'L',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('students.index', [
            'search' => 'Siswa Filtered',
        ]));

        $response->assertOk();
        $response->assertViewHas('students', function ($students): bool {
            return $students->total() === 1
                && $students->first()?->name === 'Siswa Filtered';
        });
    }

    public function test_student_listing_respects_per_page_option(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.index', [
            'per_page' => 25,
        ]));

        $response->assertOk();
        $response->assertViewHas('students', fn ($students) => $students->perPage() === 25 && $students->count() === 25);
    }

    public function test_student_listing_ignores_invalid_per_page_option(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.index', [
            'per_page' => 9999,
        ]));

        $response->assertOk();
        $response->assertViewHas('students', fn ($students) => $students->perPage() === 10 && $students->count() === 10);
    }
}
