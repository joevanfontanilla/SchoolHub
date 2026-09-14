<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Database;
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
            'programs'   => Database::select('SELECT id, code, name FROM programs ORDER BY is_active DESC, code'),
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

        $saved = Database::selectOne('SELECT curriculum_id FROM subjects WHERE id = ?', [$savedId]);
        $this->success(
            $id === null ? 'Subject created.' : 'Subject saved.',
            $saved['curriculum_id'] !== null ? '/registrar/curricula/' . $saved['curriculum_id'] : '/registrar/subjects'
        );
    }

    /**
     * The curriculum is fixed on edit, and chosen before the form on create
     * (?curriculum_id=), so the prerequisite list only offers subjects the
     * service will accept.
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
            'allSubjects'     => Catalog::prerequisiteOptions($curriculumId, $subject === null ? null : (int) $subject['id']),
            'prerequisiteIds' => $subject === null ? [] : Catalog::prerequisiteIds((int) $subject['id']),
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
        $enrolled = $section === null ? 0 : (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'",
            [$section['id']]
        );

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
        $row = Database::selectOne("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
        if ($row === null) {
            throw HttpException::notFound("That {$label} was not found.");
        }
        return $row;
    }
}
