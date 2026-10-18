<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Catalog;
use App\Models\Program;
use App\Models\RegistrarReport;
use App\Models\Term;
use App\Services\AuditService;
use App\Services\CsvExport;

/**
 * Registrar reports (docs/15): the enrollment list (students by program, year level, and
 * section), applicants by status, requirements status, and enrollment history. Each is on
 * screen, printable, and a CSV export. Exports are audited because they take student
 * records out of the system.
 */
final class ReportController extends Controller
{
    /** Rows shown per page on screen; print and CSV always include every row. */
    private const PER_PAGE = 100;

    public function index(Request $request): void
    {
        Response::redirect('/registrar/reports/enrollment');
    }

    // Enrollment list -------------------------------------------------------------

    public function enrollment(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->enrollmentReport($filters);
        [$rows, $page, $pages] = $this->paginate($report['rows'], $request);

        $this->appView('registrar/reports/enrollment', [
            'title'      => 'Enrollment List',
            'filters'    => $filters,
            'terms'      => Catalog::allTerms(),
            'programs'   => Program::active(),
            'yearLevels' => Catalog::yearLevels(),
            'sections'   => $filters['term_id'] !== null ? Catalog::sections(['term_id' => $filters['term_id'], 'program_id' => $filters['program_id']]) : [],
            'report'     => $report,
            'rows'       => $rows,
            'page'       => $page,
            'pages'      => $pages,
        ]);
    }

    public function enrollmentPrint(Request $request): void
    {
        $filters = $this->filters($request);
        $this->printView('print/registrar-enrollment', [
            'title'  => 'Enrollment List',
            'scope'  => $this->scopeLabel($filters),
            'report' => $this->enrollmentReport($filters),
            'back'   => '/registrar/reports/enrollment?' . $this->query($filters),
        ]);
    }

    public function enrollmentExport(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->enrollmentReport($filters);
        $this->audit('enrollment', $filters, count($report['rows']));

        CsvExport::send('enrollment-list-' . date('Y-m-d') . '.csv',
            ['Student No.', 'Last name', 'First name', 'Middle name', 'Email', 'Program', 'Year level', 'Section', 'Student type', 'Units', 'Enrolled'],
            array_map(static fn (array $row): array => [
                $row['student_number'], $row['last_name'], $row['first_name'], $row['middle_name'] ?? '', $row['email'],
                $row['program_code'], $row['year_level_name'], $row['section_code'], $row['student_type'],
                $row['total_units'], $row['enrolled_at'],
            ], $report['rows'])
        );
    }

    // Applicants by status --------------------------------------------------------

    public function applications(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->applicationReport($filters);
        [$rows, $page, $pages] = $this->paginate($report['rows'], $request);

        $this->appView('registrar/reports/applications', [
            'title'    => 'Applicants by Status',
            'filters'  => $filters,
            'terms'    => Catalog::allTerms(),
            'programs' => Program::active(),
            'statuses' => RegistrarReport::APPLICATION_STATUSES,
            'report'   => $report,
            'rows'     => $rows,
            'page'     => $page,
            'pages'    => $pages,
        ]);
    }

    public function applicationsPrint(Request $request): void
    {
        $filters = $this->filters($request);
        $this->printView('print/registrar-applications', [
            'title'  => 'Applicants by Status',
            'scope'  => $this->scopeLabel($filters),
            'report' => $this->applicationReport($filters),
            'back'   => '/registrar/reports/applications?' . $this->query($filters),
        ]);
    }

    public function applicationsExport(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->applicationReport($filters);
        $this->audit('applications', $filters, count($report['rows']));

        CsvExport::send('applicants-by-status-' . date('Y-m-d') . '.csv',
            ['Application No.', 'Student No.', 'Last name', 'First name', 'Email', 'Program', 'Year level', 'Student type', 'Application status', 'Requirements verified', 'Requirements required', 'Payment status', 'Enrollment', 'Submitted'],
            array_map(static fn (array $row): array => [
                $row['application_number'], $row['student_number'] ?? '', $row['last_name'], $row['first_name'], $row['email'],
                $row['program_code'], $row['year_level_name'], $row['student_type'], $row['application_status'],
                (int) $row['verified_count'], (int) $row['required_count'], $row['payment_status'], $row['enrollment_state'], $row['submitted_at'] ?? '',
            ], $report['rows'])
        );
    }

    // Requirements status ---------------------------------------------------------

    public function requirements(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->requirementReport($filters);
        [$rows, $page, $pages] = $this->paginate($report['outstanding'], $request);

        $this->appView('registrar/reports/requirements', [
            'title'    => 'Requirements Status',
            'filters'  => $filters,
            'terms'    => Catalog::allTerms(),
            'programs' => Program::active(),
            'statuses' => RegistrarReport::REQUIREMENT_STATUSES,
            'report'   => $report,
            'rows'     => $rows,
            'page'     => $page,
            'pages'    => $pages,
        ]);
    }

    public function requirementsPrint(Request $request): void
    {
        $filters = $this->filters($request);
        $this->printView('print/registrar-requirements', [
            'title'    => 'Requirements Status',
            'scope'    => $this->scopeLabel($filters),
            'statuses' => RegistrarReport::REQUIREMENT_STATUSES,
            'report'   => $this->requirementReport($filters),
            'back'     => '/registrar/reports/requirements?' . $this->query($filters),
        ]);
    }

    public function requirementsExport(Request $request): void
    {
        $filters = $this->filters($request);
        $report = $this->requirementReport($filters);
        $this->audit('requirements', $filters, count($report['outstanding']));

        // One line per missing requirement, so the sheet can be sorted and filtered by document.
        $lines = [];
        foreach ($report['outstanding'] as $application) {
            foreach ($application['missing'] as $missing) {
                $lines[] = [
                    $application['application_number'], $application['student_number'] ?? '', $application['last_name'], $application['first_name'],
                    $application['email'], $application['program_code'], $application['year_level_name'], $application['application_status'],
                    $missing['name'], $missing['status'],
                ];
            }
        }
        CsvExport::send('requirements-outstanding-' . date('Y-m-d') . '.csv',
            ['Application No.', 'Student No.', 'Last name', 'First name', 'Email', 'Program', 'Year level', 'Application status', 'Requirement', 'Requirement status'],
            $lines
        );
    }

    // Enrollment history ----------------------------------------------------------

    public function history(Request $request): void
    {
        $programId = $this->positive($request, 'program_id');
        $this->appView('registrar/reports/history', [
            'title'     => 'Enrollment History',
            'programId' => $programId,
            'programs'  => Program::active(),
            'report'    => RegistrarReport::enrollmentHistory($programId),
        ]);
    }

    public function historyPrint(Request $request): void
    {
        $programId = $this->positive($request, 'program_id');
        $this->printView('print/registrar-history', [
            'title'  => 'Enrollment History',
            'scope'  => $this->scopeLabel(['term_id' => null, 'program_id' => $programId]),
            'report' => RegistrarReport::enrollmentHistory($programId),
            'back'   => '/registrar/reports/history' . ($programId !== null ? '?program_id=' . $programId : ''),
        ]);
    }

    public function historyExport(Request $request): void
    {
        $programId = $this->positive($request, 'program_id');
        $report = RegistrarReport::enrollmentHistory($programId);
        $this->audit('history', ['program_id' => $programId], count($report['terms']));

        CsvExport::send('enrollment-history-' . date('Y-m-d') . '.csv',
            ['Term', 'Academic year', 'Starts', 'Applicants', 'Approved', 'Enrolled', 'New', 'Old', 'Transferee', 'Summer', 'Units', ...$report['programs']],
            array_map(static fn (array $term): array => [
                $term['name'], $term['academic_year_code'], $term['start_date'], $term['applicants'], $term['approved'],
                $term['enrolled'], $term['new'], $term['old'], $term['transferee'], $term['summer'], $term['units'],
                ...array_map(static fn (string $code) => $term['by_program'][$code] ?? 0, $report['programs']),
            ], $report['terms'])
        );
    }

    // Helpers ---------------------------------------------------------------------

    /** @param array<string,?int|?string> $filters */
    private function enrollmentReport(array $filters): array
    {
        return $filters['term_id'] === null
            ? ['rows' => [], 'groups' => [], 'by_type' => [], 'units' => 0.0]
            : RegistrarReport::enrollmentList($filters['term_id'], $filters['program_id'], $filters['year_level_id'], $filters['section_id']);
    }

    private function applicationReport(array $filters): array
    {
        return $filters['term_id'] === null
            ? ['rows' => [], 'counts' => array_fill_keys(RegistrarReport::APPLICATION_STATUSES, 0), 'matrix' => []]
            : RegistrarReport::applicationsByStatus($filters['term_id'], $filters['program_id'], $filters['status']);
    }

    private function requirementReport(array $filters): array
    {
        return $filters['term_id'] === null
            ? ['types' => [], 'outstanding' => [], 'applications' => 0, 'complete' => 0]
            : RegistrarReport::requirementsStatus($filters['term_id'], $filters['program_id']);
    }

    /**
     * Filters from the query string. The term defaults to the one being enrolled; an id
     * that doesn't exist simply matches nothing.
     *
     * @return array{term_id:?int,program_id:?int,year_level_id:?int,section_id:?int,status:?string}
     */
    private function filters(Request $request): array
    {
        $status = (string) $request->input('status', '');
        return [
            'term_id'       => $this->positive($request, 'term_id') ?? RegistrarReport::defaultTermId(),
            'program_id'    => $this->positive($request, 'program_id'),
            'year_level_id' => $this->positive($request, 'year_level_id'),
            'section_id'    => $this->positive($request, 'section_id'),
            'status'        => in_array($status, RegistrarReport::APPLICATION_STATUSES, true) ? $status : null,
        ];
    }

    private function positive(Request $request, string $key): ?int
    {
        $value = (int) $request->input($key, 0);
        return $value > 0 ? $value : null;
    }

    private function query(array $filters): string
    {
        return http_build_query(array_filter($filters, static fn ($value) => $value !== null));
    }

    /** @return array{0:array<int,mixed>,1:int,2:int} this page's rows, the page, and the page count */
    private function paginate(array $rows, Request $request): array
    {
        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        return [array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), $page, $pages];
    }

    private function audit(string $report, array $filters, int $rows): void
    {
        AuditService::log('reports.exported', 'reports', null, null, null,
            ['report' => 'registrar.' . $report, 'filters' => array_filter($filters, static fn ($v) => $v !== null), 'rows' => $rows],
            'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);
    }

    /** "2nd Semester, A.Y. 2026-2027 · BSIT · Second Year · BSIT-2A · Approved" */
    private function scopeLabel(array $filters): string
    {
        $parts = [];
        if (($filters['term_id'] ?? null) !== null && ($term = Term::find((int) $filters['term_id'])) !== null) {
            $parts[] = $term['name'] . ', A.Y. ' . $term['academic_year_code'];
        }
        foreach (Program::active() as $program) {
            if ((int) $program['id'] === ($filters['program_id'] ?? null)) {
                $parts[] = $program['code'];
            }
        }
        foreach (Catalog::yearLevels() as $level) {
            if ((int) $level['id'] === ($filters['year_level_id'] ?? null)) {
                $parts[] = $level['name'];
            }
        }
        if (($filters['section_id'] ?? null) !== null && ($section = Catalog::section((int) $filters['section_id'])) !== null) {
            $parts[] = $section['code'];
        }
        if (($filters['status'] ?? null) !== null) {
            $parts[] = RegistrarReport::label($filters['status']);
        }
        return $parts === [] ? 'All terms and programs' : implode(' · ', $parts);
    }
}
