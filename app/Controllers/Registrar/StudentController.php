<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\ClassSchedule;
use App\Models\RegistrarQueue;
use App\Models\Student;
use App\Services\AcademicRecordService;
use App\Services\EnlistmentService;
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
        $enrollment = AcademicRecordService::currentEnrollment($id);
        $enrollmentId = $enrollment === null ? null : (int) $enrollment['id'];

        $this->appView('registrar/students/show', [
            'enrollment'   => $enrollment,
            'classes'      => $enrollmentId === null ? [] : AcademicRecordService::classesForEnrollment($enrollmentId),
            'notEnlisted'  => $enrollmentId === null ? [] : EnlistmentService::notEnlisted($enrollmentId),
            'addable'      => $enrollmentId === null ? [] : ClassSchedule::addableFor($enrollmentId),
            'unitLimit'    => $enrollment === null ? null : EnlistmentService::unitLimit($enrollment),
            'history'      => AcademicRecordService::history($id),
            'credits'      => \App\Models\CreditedSubject::forStudent($id),
            'creditable'   => Auth::user()->hasPermission('academic.credit') ? \App\Models\CreditedSubject::creditableFor($id) : [],
            'canCredit'    => Auth::user()->hasPermission('academic.credit'),
            'documents'    => \App\Models\StudentDocuments::forStudent($id, false),
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
            StudentRecordService::update(Auth::user(), $id, $request->all(), \App\Core\UploadedFile::fromRequest($_FILES['photo'] ?? []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/students/{$id}#edit");
            return;
        }

        $this->success('Student record corrected.', "/registrar/students/{$id}");
    }

    /** Add one class to the student's current enrollment, with an override reason when needed. */
    public function addClass(Request $request): void
    {
        $student = $this->student($request);
        $id = (int) $student['id'];
        $enrollment = AcademicRecordService::currentEnrollment($id);
        if ($enrollment === null) {
            throw HttpException::notFound('This student has no current enrollment.');
        }

        try {
            EnlistmentService::addClass(Auth::user(), (int) $enrollment['id'], (int) $request->input('class_id', 0), (string) $request->input('override_reason', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/students/{$id}#classes");
            return;
        }
        $this->success('Class added to the student\'s load.', "/registrar/students/{$id}#classes");
    }

    /** Drop one enlistment. It stays on the record as dropped. */
    public function dropClass(Request $request): void
    {
        $enlistmentId = (int) $request->param('id');
        $studentId = ClassSchedule::studentOfEnlistment($enlistmentId);
        if ($studentId === null) {
            throw HttpException::notFound('That enlistment was not found.');
        }

        try {
            EnlistmentService::dropClass(Auth::user(), $enlistmentId, (string) $request->input('drop_reason', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/students/{$studentId}#classes");
            return;
        }
        $this->success('Class dropped. It stays on the record as dropped.', "/registrar/students/{$studentId}#classes");
    }

    /** @return array<string,mixed> */
    /** Stream a student's photo for the record page (registrar with students.view). */
    public function photo(Request $request): void
    {
        StudentProfileService::streamPhoto((int) $this->student($request)['id']);
    }

    /** Credit a subject to this student (transfer credit; academic.credit). */
    public function creditSubject(Request $request): void
    {
        $id = (int) $this->student($request)['id'];
        try {
            \App\Services\CreditedSubjectService::add(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/students/{$id}#credits");
            return;
        }
        $this->success('Subject credited to the student.', "/registrar/students/{$id}#credits");
    }

    /** Remove a credited subject from this student (academic.credit). */
    public function removeCredit(Request $request): void
    {
        $id = (int) $this->student($request)['id'];
        \App\Services\CreditedSubjectService::remove(Auth::user(), $id, (int) $request->param('creditId'));
        $this->success('Credited subject removed.', "/registrar/students/{$id}#credits");
    }

    private function student(Request $request): array
    {
        $student = RegistrarQueue::student((int) $request->param('id'));
        if ($student === null) {
            throw HttpException::notFound('That student was not found.');
        }
        return $student;
    }
}
