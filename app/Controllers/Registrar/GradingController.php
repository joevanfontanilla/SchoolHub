<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\GradePeriod;
use App\Models\Term;
use App\Services\GradeSubmissionService;
use App\Services\GradingService;

/**
 * Grading periods per term: open, close, and weights (docs/27). The registrar
 * never enters grades here (docs/04).
 */
final class GradingController extends Controller
{
    public function index(Request $request): void
    {
        $terms = Catalog::allTerms();
        $termId = (int) $request->input('term_id', 0) ?: $this->defaultTermId($terms);
        $term = $termId > 0 ? Term::find($termId) : null;
        if ($termId > 0 && $term === null) {
            throw HttpException::notFound('That term was not found.');
        }

        $periods = $term === null ? [] : GradePeriod::forTerm($termId);

        $this->appView('registrar/grading/index', [
            'title'   => 'Grading periods',
            'terms'   => $terms,
            'term'    => $term,
            'periods' => $periods,
            'graded'  => array_sum(array_column($periods, 'grade_count')) > 0,
            'missingSemiFinals' => $periods !== [] && !in_array('Semi-Finals', array_column($periods, 'name'), true),
        ]);
    }

    /** The sheets teachers have submitted, waiting to be confirmed (or any status, by filter). */
    public function submissions(Request $request): void
    {
        $status = strtoupper((string) $request->input('status', GradeSubmissionService::SUBMITTED));
        $this->appView('registrar/grading/submissions', [
            'title'       => 'Grade submissions',
            'status'      => $status,
            'submissions' => GradeSubmissionService::listing($status),
            'waiting'     => GradeSubmissionService::countSubmitted(),
        ]);
    }

    public function showSubmission(Request $request): void
    {
        $submission = GradeSubmissionService::find((int) $request->param('id'));
        if ($submission === null) {
            throw HttpException::notFound('That grade submission was not found.');
        }
        $this->appView('registrar/grading/submission', [
            'title'      => $submission['period_name'] . ' grades · ' . $submission['subject_code'],
            'submission' => $submission,
            'sheet'      => GradeSubmissionService::sheet((int) $submission['class_schedule_id'], (int) $submission['grade_period_id']),
        ]);
    }

    public function confirmSubmission(Request $request): void
    {
        $id = (int) $request->param('id');
        try {
            GradeSubmissionService::confirm(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/registrar/grading/submissions/{$id}");
            return;
        }
        $this->success('Confirmed. The grades are released: students can see them now and were told.', '/registrar/grading/submissions');
    }

    public function confirmMany(Request $request): void
    {
        $ids = (array) $request->input('ids', []);
        if ($ids === []) {
            $this->failWith(ValidationException::withMessage('Tick the sheets to confirm first.'), [], '/registrar/grading/submissions');
            return;
        }
        $count = GradeSubmissionService::confirmMany(Auth::user(), $ids);
        $this->success($count . ($count === 1 ? ' sheet was' : ' sheets were') . ' confirmed and released to students.', '/registrar/grading/submissions');
    }

    public function returnSubmission(Request $request): void
    {
        $id = (int) $request->param('id');
        try {
            GradeSubmissionService::returnToTeacher(Auth::user(), $id, (string) $request->input('reason', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/grading/submissions/{$id}");
            return;
        }
        $this->success('Returned to the teacher. Students cannot see these grades until they are submitted and confirmed again.', '/registrar/grading/submissions');
    }

    public function open(Request $request): void
    {
        $this->toggle($request, true);
    }

    public function close(Request $request): void
    {
        $this->toggle($request, false);
    }

    public function weights(Request $request): void
    {
        $termId = (int) $request->param('id');
        $back = "/registrar/grading?term_id={$termId}";

        try {
            GradingService::setWeights(Auth::user(), $termId, (array) $request->input('weights', []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success('Weights saved.', $back);
    }

    /** Add the Semi-Finals period to a term that lacks it. */
    public function addSemiFinals(Request $request): void
    {
        $termId = (int) $request->param('id');
        $back = "/registrar/grading?term_id={$termId}";

        try {
            GradingService::addSemiFinals(Auth::user(), $termId);
        } catch (ValidationException $e) {
            $this->failWith($e, [], $back);
            return;
        }
        $this->success('Semi-Finals was added. It is closed: open it when teachers should enter Semi-Finals grades.', $back);
    }

    private function toggle(Request $request, bool $open): void
    {
        $period = GradePeriod::find((int) $request->param('id'));
        if ($period === null) {
            throw HttpException::notFound('That grading period was not found.');
        }
        $back = '/registrar/grading?term_id=' . $period['term_id'];

        try {
            $open
                ? GradingService::openPeriod(Auth::user(), (int) $period['id'])
                : GradingService::closePeriod(Auth::user(), (int) $period['id']);
        } catch (ValidationException $e) {
            $this->failWith($e, [], $back);
            return;
        }

        $this->success($open
            ? "{$period['name']} is open. Teachers can enter and correct {$period['name']} grades."
            : "{$period['name']} is closed. Its grades are locked and students were notified.", $back);
    }

    /** The term containing today, else the most recent one. @param array<int,array<string,mixed>> $terms */
    private function defaultTermId(array $terms): int
    {
        $today = date('Y-m-d');
        foreach ($terms as $term) {
            if ($term['start_date'] <= $today && $today <= $term['end_date']) {
                return (int) $term['id'];
            }
        }
        return (int) ($terms[0]['id'] ?? 0);
    }
}
