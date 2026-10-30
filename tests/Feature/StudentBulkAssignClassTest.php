<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentBulkAssignClassTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    private User $teacher;

    private SchoolClass $classA;

    private SchoolClass $classB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->operator = User::factory()->operator()->create();
        $this->teacher = User::factory()->waliKelas()->create();

        $this->classA = SchoolClass::create([
            'name' => 'X-RPL 1',
            'academic_year' => '2026/2027',
        ]);

        $this->classB = SchoolClass::create([
            'name' => 'X-RPL 2',
            'academic_year' => '2026/2027',
        ]);
    }

    public function test_admin_can_bulk_assign_students_to_a_class(): void
    {
        $student1 = Student::create([
            'nis' => '1001',
            'name' => 'Ahmad Santoso',
            'gender' => 'L',
            'class_id' => null,
            'active' => true,
        ]);

        $student2 = Student::create([
            'nis' => '1002',
            'name' => 'Budi Pratama',
            'gender' => 'L',
            'class_id' => null,
            'active' => true,
        ]);

        $student3 = Student::create([
            'nis' => '1003',
            'name' => 'Citra Lestari',
            'gender' => 'P',
            'class_id' => $this->classA->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->post(route('students.bulk-assign-class'), [
            'class_id' => $this->classB->id,
            'ids' => [$student1->id, $student2->id, $student3->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('students', ['id' => $student1->id, 'class_id' => $this->classB->id]);
        $this->assertDatabaseHas('students', ['id' => $student2->id, 'class_id' => $this->classB->id]);
        $this->assertDatabaseHas('students', ['id' => $student3->id, 'class_id' => $this->classB->id]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'bulk_assign_student_class',
            'entity_type' => 'Student',
        ]);
    }

    public function test_operator_can_also_bulk_assign_students(): void
    {
        $student = Student::create([
            'nis' => '2001',
            'name' => 'Doni Kusuma',
            'gender' => 'L',
            'class_id' => null,
            'active' => true,
        ]);

        $response = $this->actingAs($this->operator)->post(route('students.bulk-assign-class'), [
            'class_id' => $this->classA->id,
            'student_ids' => [$student->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('students', ['id' => $student->id, 'class_id' => $this->classA->id]);
    }

    public function test_bulk_assign_validation_fails_on_empty_ids_or_invalid_class(): void
    {
        $response = $this->actingAs($this->admin)->post(route('students.bulk-assign-class'), [
            'class_id' => 99999,
            'ids' => [],
        ]);

        $response->assertSessionHasErrors(['class_id', 'ids']);
    }

    public function test_teacher_cannot_bulk_assign_students(): void
    {
        $response = $this->actingAs($this->teacher)->post(route('students.bulk-assign-class'), [
            'class_id' => $this->classA->id,
            'ids' => [1],
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_filter_students_by_unassigned_class(): void
    {
        $unassigned = Student::create([
            'nis' => '3001',
            'name' => 'Eka Siswa Tanpa Kelas',
            'gender' => 'L',
            'class_id' => null,
            'active' => true,
        ]);

        $assigned = Student::create([
            'nis' => '3002',
            'name' => 'Fajar Siswa Punya Kelas',
            'gender' => 'L',
            'class_id' => $this->classA->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('students.index', ['class_id' => 'unassigned']));

        $response->assertOk();
        $response->assertSee('Eka Siswa Tanpa Kelas');
        $response->assertDontSee('Fajar Siswa Punya Kelas');
    }
}
