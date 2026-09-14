<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\RegistrarQueue;
use App\Models\Student;
use App\Services\StudentProfileService;
use App\Services\StudentRecordService;

/**
 * Student records (docs/11 "Student records", docs/04 "manage student records").
 */
final class StudentController extends Controller
{
    public function index(Request $request): void
    {
        $q = (string) $request->input('q', '');
        $status = (string) $request->input('status', '');

        $this->appView('registrar/students/index', [
            'title'  => 'Students',
            'q'      => $q,
            'status' => $status,
            'result' => RegistrarQueue::students($q, $status, (int) $request->input('page', 1)),
        ]);
    }

    public function show(Request $request): void
    {
        $student = $this->student($request);
        $id = (int) $student['id'];

        $this->appView('registrar/students/show', [
            'title'        => trim($student['first_name'] . ' ' . $student['last_name']),
            'student'      => $student,
            'profile'      => Student::profile($id),
            'guardians'    => Student::guardians($id),
            'applications' => RegistrarQueue::applicationsOfStudent($id),
            'missing'      => StudentProfileService::missingFields($id),
            'sexes'        => StudentProfileService::SEXES,
            'civilStatus'  => StudentProfileService::CIVIL_STATUSES,
        ]);
    }

    public function update(Request $request): void
    {
        $student = $this->student($request);
        $id = (int) $student['id'];

        try {
            StudentRecordService::update(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/students/{$id}#edit");
            return;
        }

        $this->success('Student record corrected.', "/registrar/students/{$id}");
    }

    /** @return array<string,mixed> */
    private function student(Request $request): array
    {
        $student = RegistrarQueue::student((int) $request->param('id'));
        if ($student === null) {
            throw HttpException::notFound('That student was not found.');
        }
        return $student;
    }
}
