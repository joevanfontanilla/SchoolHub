<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\ClassSchedule;
use App\Models\GradePeriod;

/**
 * Teacher dashboard (docs/12): today's classes and grading still to do.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();
        $today = date('Y-m-d');
        $weekday = (int) date('N');

        $classes = ClassSchedule::forTeacher($user->id);
        $current = array_values(array_filter($classes, static fn ($c) => $c['term_start'] <= $today && $today <= $c['term_end']));

        $todayBlocks = array_values(array_filter(ClassSchedule::blocks($current), static fn ($b) => $b['day'] === $weekday));
        $todayClasses = [];
        foreach ($current as $class) {
            foreach ($class['meetings'] as $meeting) {
                if ((int) $meeting['day_of_week'] === $weekday) {
                    $todayClasses[] = $class + ['meeting' => $meeting];
                }
            }
        }
        usort($todayClasses, static fn ($a, $b) => $a['meeting']['start_time'] <=> $b['meeting']['start_time']);

        $open = GradePeriod::openForTeacher($user->id);
        $missing = 0;
        foreach ($classes as $class) {
            foreach ($open as $period) {
                if ((int) $period['term_id'] === (int) $class['term_id']
                    && ClassSchedule::gradedCount((int) $class['id'], (int) $period['id']) < (int) $class['enlisted_count']) {
                    $missing++;
                }
            }
        }

        $this->appView('dashboards/teacher', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'classes'       => $classes,
            'todayClasses'  => $todayClasses,
            'open'          => $open,
            'cards'         => [
                ['label' => 'Assigned classes', 'value' => (string) count($classes), 'hint' => count($current) . ' this term', 'tone' => 'neutral'],
                ['label' => "Today's classes", 'value' => (string) count($todayBlocks), 'hint' => date('l'), 'tone' => 'neutral'],
                ['label' => 'Open grading periods', 'value' => (string) count($open), 'hint' => $open === [] ? 'Opened by the registrar' : implode(', ', array_unique(array_column($open, 'name'))), 'tone' => $open === [] ? 'neutral' : 'success'],
                ['label' => 'Grade sheets to finish', 'value' => (string) $missing, 'hint' => $missing === 0 ? 'Nothing pending' : 'Students without a grade', 'tone' => $missing === 0 ? 'neutral' : 'warning'],
            ],
        ]);
    }
}
