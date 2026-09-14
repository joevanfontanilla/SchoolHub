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

    // -- Subjects --------------------------------------------------------------

    public function subjects(Request $request): void
    {
        $programId = (int) $request->input('program_id', 0) ?: null;
        $this->appView('registrar/catalog/subjects', [
            'title'     => 'Subjects',
            'subjects'  => Catalog::subjects($programId),
            'programs'  => Program::active(),
            'programId' => $programId,
        ]);
    }

    public function createSubject(Request $request): void
    {
        $this->subjectForm(null);
    }

    public function editSubject(Request $request): void
    {
        $this->subjectForm($this->find('subjects', (int) $request->param('id'), 'subject'));
    }

    public function saveSubject(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/subjects/create' : "/registrar/subjects/{$id}/edit";

        try {
            CatalogService::saveSubject(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Subject created.' : 'Subject saved.', '/registrar/subjects');
    }

    /** @param array<string,mixed>|null $subject */
    private function subjectForm(?array $subject): void
    {
        $this->appView('registrar/catalog/subject-form', [
            'title'           => $subject === null ? 'New subject' : 'Edit ' . $subject['code'],
            'subject'         => $subject,
            'programs'        => Database::select('SELECT id, code, name FROM programs ORDER BY code'),
            'yearLevels'      => Catalog::yearLevels(),
            'allSubjects'     => Database::select('SELECT id, code, title FROM subjects WHERE id <> ? ORDER BY code', [$subject['id'] ?? 0]),
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
