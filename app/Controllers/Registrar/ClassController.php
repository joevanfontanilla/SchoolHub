<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Services\ClassScheduleService;

/**
 * A section's classes: subject, teacher, and weekly meetings (docs/13).
 */
final class ClassController extends Controller
{
    public function section(Request $request): void
    {
        $section = $this->findSection((int) $request->param('id'));
        $classes = ClassSchedule::forSection((int) $section['id']);

        $this->appView('registrar/classes/section', [
            'title'    => 'Classes of ' . $section['code'],
            'section'  => $section,
            'classes'  => $classes,
            'blocks'   => ClassSchedule::blocks(array_filter($classes, static fn ($c) => $c['status'] === 'ACTIVE'), true),
            'subjects' => Catalog::subjectsForSection($section, array_column($classes, 'subject_id')),
            'teachers' => Catalog::activeTeachers(),
            'rooms'    => Room::active(),
        ]);
    }

    public function store(Request $request): void
    {
        $section = $this->findSection((int) $request->param('id'));
        $sectionId = (int) $section['id'];

        try {
            $result = ClassScheduleService::saveClass(Auth::user(), $sectionId, null, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/sections/{$sectionId}/classes#add-class");
            return;
        }

        $this->flashSync($result['sync']);
        $this->success('Class added.', "/registrar/sections/{$sectionId}/classes");
    }

    public function edit(Request $request): void
    {
        $class = $this->findClass((int) $request->param('id'));
        $section = $this->findSection((int) $class['section_id']);

        $this->appView('registrar/classes/form', [
            'title'    => 'Edit ' . $class['code'] . ' · ' . $section['code'],
            'class'    => $class,
            'section'  => $section,
            'teachers' => Catalog::activeTeachers(),
            'rooms'    => Room::active(),
        ]);
    }

    public function update(Request $request): void
    {
        $class = $this->findClass((int) $request->param('id'));
        $id = (int) $class['id'];

        // The subject of an existing class is fixed; only its teacher and meetings change here.
        $input = $request->all();
        $input['subject_id'] = $class['subject_id'];

        try {
            ClassScheduleService::saveClass(Auth::user(), (int) $class['section_id'], $id, $input);
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/classes/{$id}/edit");
            return;
        }
        $this->success('Class saved.', '/registrar/sections/' . $class['section_id'] . '/classes');
    }

    public function close(Request $request): void
    {
        $class = $this->findClass((int) $request->param('id'));
        $back = '/registrar/sections/' . $class['section_id'] . '/classes';

        try {
            ClassScheduleService::closeClass(Auth::user(), (int) $class['id']);
        } catch (ValidationException $e) {
            $this->failWith($e, [], $back);
            return;
        }
        $this->success("{$class['code']} was closed and its students dropped from it.", $back);
    }

    /** @param array<int,array{enlisted:array,skipped:array}> $sync */
    private function flashSync(array $sync): void
    {
        $enlisted = 0;
        $skipped = [];
        foreach ($sync as $report) {
            $enlisted += count($report['enlisted']);
            foreach ($report['skipped'] as $skip) {
                $skipped[] = $skip['reason'];
            }
        }
        if ($enlisted > 0) {
            Session::flash('info', "{$enlisted} enrolled student" . ($enlisted === 1 ? ' was' : 's were') . ' enlisted in the new class.');
        }
        if ($skipped !== []) {
            Session::flash('warning', count($skipped) . ' student' . (count($skipped) === 1 ? ' was' : 's were') . ' not enlisted: ' . implode(' ', array_unique($skipped)) . ' Review them on each student\'s record.');
        }
    }

    /** @return array<string,mixed> */
    private function findSection(int $id): array
    {
        $section = Catalog::section($id);
        if ($section === null) {
            throw HttpException::notFound('That section was not found.');
        }
        return $section;
    }

    /** @return array<string,mixed> */
    private function findClass(int $id): array
    {
        $class = ClassSchedule::find($id);
        if ($class === null) {
            throw HttpException::notFound('That class was not found.');
        }
        return $class;
    }
}
