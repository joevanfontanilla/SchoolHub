<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\Curriculum;
use App\Models\Program;
use App\Services\CatalogService;

/**
 * Programs, subjects, and sections (docs/11, docs/13).
 */
final class CatalogController extends Controller
{
    // -- Programs --------------------------------------------------------------

    public function programs(Request $request): void
    {
        $this->appView('registrar/catalog/programs', ['title' => 'Programs', 'programs' => Catalog::programs()]);
    }

    public function createProgram(Request $request): void
    {
        $this->appView('registrar/catalog/program-form', ['title' => 'New program', 'program' => null]);
    }

    public function editProgram(Request $request): void
    {
        $program = $this->find('programs', (int) $request->param('id'), 'program');
        $this->appView('registrar/catalog/program-form', ['title' => 'Edit ' . $program['code'], 'program' => $program]);
    }

    public function saveProgram(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/programs/create' : "/registrar/programs/{$id}/edit";

        try {
            CatalogService::saveProgram(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Program created.' : 'Program saved.', '/registrar/programs');
    }

    // -- Curricula -------------------------------------------------------------

    public function curricula(Request $request): void
    {
        $this->appView('registrar/catalog/curricula', ['title' => 'Curricula', 'curricula' => Curriculum::all()]);
    }

    public function showCurriculum(Request $request): void
    {
        $curriculum = Curriculum::find((int) $request->param('id'));
        if ($curriculum === null) {
            throw HttpException::notFound('That curriculum was not found.');
        }
        $this->appView('registrar/catalog/curriculum', [
            'title'      => $curriculum['code'],
            'curriculum' => $curriculum,
            'terms'      => Curriculum::grid((int) $curriculum['id']),
            'totalUnits' => Curriculum::totalUnits((int) $curriculum['id']),
            'deleteBlockers' => CatalogService::curriculumDeleteBlockers((int) $curriculum['id']),
        ]);
    }

    public function createCurriculum(Request $request): void
    {
        $this->appView('registrar/catalog/curriculum-form', ['title' => 'New curriculum', 'curriculum' => null, 'programs' => Program::active()]);
    }

    public function editCurriculum(Request $request): void
    {
        $curriculum = $this->find('curricula', (int) $request->param('id'), 'curriculum');
        $this->appView('registrar/catalog/curriculum-form', [
            'title'      => 'Edit ' . $curriculum['code'],
            'curriculum' => $curriculum,
            'programs'   => Catalog::allPrograms(),
        ]);
    }

    public function saveCurriculum(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/curricula/create' : "/registrar/curricula/{$id}/edit";

        try {
            $savedId = CatalogService::saveCurriculum(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Curriculum created.' : 'Curriculum saved.', "/registrar/curricula/{$savedId}");
    }

    public function deleteCurriculum(Request $request): void
    {
        $curriculum = $this->find('curricula', (int) $request->param('id'), 'curriculum');
        try {
            $subjects = CatalogService::deleteCurriculum(Auth::user(), (int) $curriculum['id'], (string) $request->input('reason', ''), (string) $request->input('confirm_code', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/registrar/curricula/' . $curriculum['id']);
            return;
        }
        $this->success($curriculum['code'] . ' was deleted' . ($subjects > 0 ? ', with its ' . $subjects . ($subjects === 1 ? ' subject.' : ' subjects.') : '.'), '/registrar/curricula');
    }

    public function deleteSubject(Request $request): void
    {
        $subject = $this->find('subjects', (int) $request->param('id'), 'subject');
        try {
            CatalogService::deleteSubject(Auth::user(), (int) $subject['id'], (string) $request->input('reason', ''), (string) $request->input('confirm_code', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/registrar/subjects/' . $subject['id'] . '/edit');
            return;
        }
        $this->success($subject['code'] . ' was deleted.', $subject['curriculum_id'] !== null ? '/registrar/curricula/' . $subject['curriculum_id'] : '/registrar/subjects');
    }

    // -- Subjects --------------------------------------------------------------

    public function subjects(Request $request): void
    {
        $programId = (int) $request->input('program_id', 0) ?: null;
        $curriculumId = (int) $request->input('curriculum_id', 0) ?: null;
        $this->appView('registrar/catalog/subjects', [
            'title'        => 'Subjects',
            'subjects'     => Catalog::subjects($programId, $curriculumId),
            'programs'     => Program::active(),
            'curricula'    => Curriculum::options(),
            'programId'    => $programId,
            'curriculumId' => $curriculumId,
        ]);
    }

    public function createSubject(Request $request): void
    {
        $this->subjectForm(null, (int) $request->input('curriculum_id', 0) ?: null);
    }

    public function editSubject(Request $request): void
    {
        $this->subjectForm($this->find('subjects', (int) $request->param('id'), 'subject'));
    }

    public function saveSubject(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/subjects/create' : "/registrar/subjects/{$id}/edit";

        $curriculumId = (int) $request->input('curriculum_id', 0);
        if ($id === null && $curriculumId > 0) {
            $back .= '?curriculum_id=' . $curriculumId;
        }

        try {
            $savedId = CatalogService::saveSubject(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $saved = Catalog::row('subjects', $savedId);
        if ($saved['curriculum_id'] === null) {
            $this->success($id === null ? 'General subject created.' : 'Subject saved.', '/registrar/subjects');
            return;
        }

        $curriculum = Curriculum::find((int) $saved['curriculum_id']);
        $year = null;
        foreach (Catalog::yearLevels() as $level) {
            if ((int) $level['id'] === (int) $saved['year_level_id']) {
                $year = $level;
            }
        }
        $term = (int) ($saved['term_sequence'] ?? 0);
        $where = $year !== null ? $year['name'] . ' · ' . (Curriculum::TERM_NAMES[$term] ?? 'any term') : 'Unscheduled';
        $anchor = $year !== null ? '#term-' . (int) $year['sequence'] . '-' . $term : '';
        $this->success(
            $saved['code'] . ($id === null ? ' was added to ' : ' was saved in ') . $curriculum['code'] . ', ' . $where . '.',
            '/registrar/curricula/' . $saved['curriculum_id'] . $anchor
        );
    }

    /**
     * The curriculum is fixed on edit. On create it is a field of the form itself (preselected from ?curriculum_id=), and the
     * prerequisite list follows it in the browser; the service still refuses a prerequisite from another curriculum.
     *
     * @param array<string,mixed>|null $subject
     */
    private function subjectForm(?array $subject, ?int $curriculumId = null): void
    {
        if ($subject !== null) {
            $curriculumId = $subject['curriculum_id'] === null ? null : (int) $subject['curriculum_id'];
        }
        $curriculum = $curriculumId === null ? null : Curriculum::find($curriculumId);
        if ($curriculumId !== null && $curriculum === null) {
            throw HttpException::notFound('That curriculum was not found.');
        }

        $this->appView('registrar/catalog/subject-form', [
            'title'           => $subject === null ? 'New subject' : 'Edit ' . $subject['code'],
            'subject'         => $subject,
            'curriculum'      => $curriculum,
            'curricula'       => Curriculum::options(),
            'yearLevels'      => Catalog::yearLevels(),
            'allSubjects'     => $subject === null
                ? Catalog::prerequisiteOptionsForAll()
                : Catalog::prerequisiteOptions($curriculumId, (int) $subject['id']),
            'prerequisiteIds' => $subject === null ? [] : Catalog::prerequisiteIds((int) $subject['id']),
            'deleteBlockers'  => $subject === null ? [] : CatalogService::subjectDeleteBlockers((int) $subject['id']),
        ]);
    }

    // -- Sections --------------------------------------------------------------

    public function sections(Request $request): void
    {
        $filters = ['term_id' => $request->input('term_id'), 'program_id' => $request->input('program_id')];
        $this->appView('registrar/catalog/sections', [
            'title'    => 'Sections',
            'sections' => Catalog::sections($filters),
            'filters'  => $filters,
            'terms'    => Catalog::allTerms(),
            'programs' => Program::active(),
        ]);
    }

    public function createSection(Request $request): void
    {
        $this->sectionForm(null);
    }

    public function editSection(Request $request): void
    {
        $this->sectionForm($this->find('sections', (int) $request->param('id'), 'section'));
    }

    public function saveSection(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/sections/create' : "/registrar/sections/{$id}/edit";

        try {
            CatalogService::saveSection(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Section created.' : 'Section saved.', '/registrar/sections');
    }

    /** @param array<string,mixed>|null $section */
    private function sectionForm(?array $section): void
    {
        $enrolled = $section === null ? 0 : Catalog::enrolledInSection((int) $section['id']);

        $this->appView('registrar/catalog/section-form', [
            'title'      => $section === null ? 'New section' : 'Edit ' . $section['code'],
            'section'    => $section,
            'enrolled'   => $enrolled,
            'programs'   => Program::active(),
            'yearLevels' => Catalog::yearLevels(),
            'terms'      => Catalog::allTerms(),
            'teachers'   => Catalog::activeTeachers(),
        ]);
    }

    /** @return array<string,mixed> */
    private function find(string $table, int $id, string $label): array
    {
        $row = Catalog::row($table, $id);
        if ($row === null) {
            throw HttpException::notFound("That {$label} was not found.");
        }
        return $row;
    }
}
