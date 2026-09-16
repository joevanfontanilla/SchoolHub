<?php
/**
 * Bachelor of Science in Tourism Management, for non-ABM senior high graduates,
 * effective SY 2024-2025.
 *
 * Transcribed from "Tourism Management Curriculum.pdf" (prepared by the Registrar,
 * noted by the Dean of Tourism Management, approved by the President and CEO,
 * contents noted 7/23/2024). Format: docs/28.
 *
 * Prerequisites the PDF abbreviates are written in full: "TOU 2" is TOU 002,
 * "TOU 7" is TOU 007, "GE 009" is GE009. The summer "GE ELEC 03" is stored as
 * GE ELEC 3, matching GE ELEC 1 and GE ELEC 2.
 */

$elective = ['description' => 'Professional elective, chosen from the free electives offered for the term.'];

return [
    'program'               => 'BSTM',
    'code'                  => 'BSTM-2024-NONABM',
    'name'                  => 'Bachelor of Science in Tourism Management (non-ABM)',
    'effective_school_year' => '2024-2025',
    'notes'                 => 'For senior high graduates of strands other than ABM; includes Bridging 1 to 5.',

    'to_confirm' => [
        'ELEC 1 to ELEC 5 are placeholders ("Students can choose from the 11 free electives"). The list of the 11 electives is not in the PDF.',
        'This is the non-ABM curriculum. The curriculum for ABM graduates (without the bridging subjects) was not provided.',
        'The internship requires "75% of academic subjects completed". Imported as 75% of this curriculum\'s units passed.',
        'POM (Production & Operations Management) requires TOU 002 (Tour and Travel Management). Imported as printed.',
    ],

    'terms' => [
        [1, 1, 26, [
            ['GE001', 'Understanding Self', 3],
            ['GE002', 'Purposive Communication', 3],
            ['GE003', 'Mathematics in the Modern World', 3],
            ['GE004', 'Readings in Philippine History', 3],
            ['CORE 1', 'Macro Perspective of Tourism & Hospitality', 3],
            ['CHRIST', 'Christian Values', 3],
            ['BRIDGING 1', 'Fundamentals of Accounting / Business Management', 3],
            ['PATH-FIT 1', 'Movement Enhancement', 2],
            ['NSTP 01', 'National Service Training Program 1', 3],
        ]],
        [1, 2, 26, [
            ['GE005', 'Science, Technology and Society', 3],
            ['GE006', 'The Contemporary World', 3],
            ['GE007', 'Art Appreciation', 3],
            ['GE008', 'Life and Works of Rizal', 3],
            ['TOU 001', 'Global Tourism, Geography & Culture', 3, ['CORE 1']],
            ['CORE 2', 'Micro Perspective of Tourism and Hospitality', 3, ['CORE 1']],
            ['BRIDGING 2', 'Organization and Management', 3],
            ['PATH-FIT 2', 'Exercise-based Fitness Activities', 2, ['PATH-FIT 1']],
            ['NSTP 02', 'National Service Training Program 2', 3, ['NSTP 01']],
        ]],
        [2, 1, 26, [
            ['GE009', 'Ethics', 3],
            ['GE ELEC 1', 'Environmental Science', 3],
            ['GE ELEC 2', 'Gender & Society', 3],
            ['TOU 002', 'Tour and Travel Management Including Tour Guiding', 3, ['CORE 1', 'TOU 001']],
            ['TOU 003', 'Applied Business Tools and Technologies', 3, [], ['lab_units' => 1]],
            ['ELEC 1', 'Prof Elec 1', 3, [], $elective],
            ['CORE 3', 'Risk Mgt as Applied to Safety, Sanitation and Security', 3],
            ['BRIDGING 3', 'Business Marketing', 3],
            ['PATH-FIT 3', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 2']],
        ]],
        [2, 2, 26, [
            ['TOU 004', 'Sustainable Tourism', 3, ['TOU 001']],
            ['TOU 005', "Tourism Policy Planning and Dev't", 3],
            ['ELEC 2', 'Prof Elec 2', 3, [], $elective],
            ['TOUR LAW', 'Tourism Law', 3],
            ['POM', 'Production & Operations Management', 3, ['TOU 002']],
            ['CORE 4', 'Philippine Tourism, Geography and Culture', 3, ['TOU 001']],
            ['BRIDGING 4', 'Business Finance', 3],
            ['TOU 006', 'Intro to Meetings, Incentives, Conferences & Events Management (MICE)', 3, ['TOU 002'], ['lab_units' => 1]],
            ['PATH-FIT 4', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 3']],
        ]],
        [2, 3, 9, [
            ['BRIDGING 5', 'Applied Economics', 3],
            ['GE ELEC 3', 'Living in the IT Era', 3],
            ['TOU 007', 'Foreign Language 1', 3],
        ]],
        [3, 1, 21, [
            ['CORE 5', 'Tourism and Hospitality Marketing', 3, ['CORE 1', 'CORE 2']],
            ['CORE 6', 'Entrepreneurship in Tourism and Hospitality', 3, ['CORE 1', 'CORE 2']],
            ['TOU 008', 'Foreign Language 2', 3, ['TOU 007']],
            ['ELEC 3', 'Prof Elec 3', 3, [], $elective],
            ['ELEC 4', 'Prof Elec 4', 3, [], $elective],
            ['ELEC 5', 'Prof Elec 5', 3, [], $elective],
            ['STAT', 'Statistics in Tourism', 3],
        ]],
        [3, 2, 18, [
            ['CORE 7', 'Multicultural Diversity in Workplace', 3, ['GE009']],
            ['CORE 8', 'Quality Service Management in Tourism and Hospitality', 3],
            ['CORE 9', 'Legal Aspects in Tourism and Hospitality', 3],
            ['CORE 10', 'Professional Development & Applied Ethics', 3, ['GE009']],
            ['TOU 009', 'Transportation Management', 3],
            ['STRAMA', 'Strategic Management', 3],
        ]],
        [4, 1, 6, [
            ['INTERN 002', 'Practicum', 6, [], ['min_progress_percent' => 75, 'description' => '600 hours.']],
        ]],
        [4, 2, 6, [
            ['TOU 010', 'Research in Tourism', 3, ['INTERN 002', 'STAT']],
            ['GE010', 'Peace Education & IP Studies/Education', 3],
        ]],
    ],
];
