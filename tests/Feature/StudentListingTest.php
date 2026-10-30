<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_student_listing_returns_every_row_for_client_side_tables(): void
    {
        $response = $this->actingAs($this->admin)->get(route('students.index'));

        $response->assertOk();
        $response->assertViewHas('students', fn ($students) => $students->count() === 30);
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
            return $students->count() === 1
                && $students->first()?->name === 'Siswa Filtered';
        });
    }
}
