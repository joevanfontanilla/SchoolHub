<?php
/**
 * Bachelor of Science in Accountancy, effective SY 2024-2025.
 *
 * Transcribed from "Accountancy Curriculum.pdf" (prepared by the Registrar, noted
 * by the President and CEO, contents noted 07-23-2024). Format: docs/28.
 *
 * Subject: [code, title, units, prerequisites, options]
 * Term:    [year level, term (1 = 1st sem, 2 = 2nd sem, 3 = summer), printed total, subjects]
 */

return [
    'program'               => 'BSA',
    'code'                  => 'BSA-2024',
    'name'                  => 'Bachelor of Science in Accountancy',
    'effective_school_year' => '2024-2025',
    'notes'                 => null,

    'to_confirm' => [
        'The PDF codes both Environmental Science (1st year) and Peace Education (4th year) as GE010. Imported Environmental Science as GELEC1, next to GELEC2 and GELEC3, and kept GE010 for Peace Education as the other programs do.',
        'INTERN and RESEARCH are titled "Accounting Information System Internship/Research", which looks copied from the BSAIS curriculum. Imported as printed.',
        'PROF8 (Auditing Assurance: Concepts and Applications 2) requires PROF2, not PROF4 (Concepts and Applications 1). Imported as printed.',
    ],

    'terms' => [
        [1, 1, 29, [
            ['GE001', 'Understanding Self', 3],
            ['GE002', 'Purposive Communication', 3],
            ['GE003', 'Mathematics in the Modern World', 3],
            ['GE004', 'Readings in Philippine History', 3],
            ['GELEC1', 'Environmental Science', 3],
            ['CORE 1', 'Financial Accounting and Reporting', 3],
            ['CORE 2', 'Managerial Economics', 3],
            ['CHRIS1', 'Christian Values', 3],
            ['PATH-FIT 1', 'Movement Enhancement', 2],
            ['NSTP 01', 'National Service Training Program 1', 3],
        ]],
        [1, 2, 26, [
            ['GE005', 'Art Appreciation', 3],
            ['GE006', 'Science, Technology and Society', 3],
            ['GE007', 'The Contemporary World', 3],
            ['GE008', 'Life and Works of Rizal', 3],
            ['CORE 3', 'Law on Obligations and Contracts', 3],
            ['CORE 4', 'Conceptual Framework and Accounting Standards', 3, ['CORE 1']],
            ['CORE 5', 'Income Taxation', 3],
            ['PATH-FIT 2', 'Exercise-based Fitness Activities', 2, ['PATH-FIT 1']],
            ['NSTP 02', 'National Service Training Program 2', 3, ['NSTP 01']],
        ]],
        [2, 1, 26, [
            ['GE009', 'Ethics', 3],
            ['CORE 6', 'Intermediate Accounting 1', 3, ['CORE 4']],
            ['CORE 7', 'Cost Accounting and Control', 3, ['CORE 1']],
            ['CORE 8', 'Intermediate Accounting 2', 3, ['CORE 4']],
            ['CORE 9', 'IT Application Tools in Business', 3],
            ['CORE 10', 'Governance, Business Ethics, Risk Management, and Internal Control', 3],
            ['CORE 11', 'Business Laws and Regulations', 3, ['CORE 3']],
            ['CORE 12', 'Economic Development', 3, ['CORE 2']],
            ['PATH-FIT 3', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 2']],
        ]],
        [2, 2, 26, [
            ['POM', 'Operations Management & TQM', 3],
            ['CORE 13', 'Regulatory Framework and Legal Issues in Business', 3, ['CORE 11', 'CORE 3']],
            ['CORE 14', 'Business Tax', 3, ['CORE 5']],
            ['CORE 15', 'Intermediate Accounting 3', 3, ['CORE 8']],
            ['CORE 16', 'Accounting Information System', 3, ['CORE 9']],
            ['CORE 17', 'Financial Management', 3],
            ['CORE 18', 'Strategic Cost Management', 3, ['CORE 7']],
            ['ELEC 1', 'Human Behavior in Organization (HBO)', 3],
            ['PATH-FIT 4', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 3']],
        ]],
        [2, 3, 9, [
            ['BOOKKEEPING', 'Bookkeeping NC III', 3, [], ['description' => "Students are required to undergo an assessment to determine their ability to meet the workplace's competency standards."]],
            ['GELEC2', 'Gender & Society', 3],
            ['GELEC3', 'Living in the IT Era', 3],
        ]],
        [3, 1, 21, [
            ['CORE 19', 'Statistical Analysis with Software Application', 3],
            ['CORE 20', 'Financial Markets', 3, ['CORE 17']],
            ['CORE 21', 'International Business and Trade', 3],
            ['CORE 22', 'Strategic Business Analysis', 3, ['CORE 18']],
            ['CORE 23', 'Management Science', 3],
            ['STRAT', 'Strategic Management', 3],
            ['ELEC 2', 'Updates in Financial Reporting Standards', 3],
        ]],
        [3, 2, 18, [
            ['PROF1', 'Accounting for Special Transactions', 3, ['CORE 6', 'CORE 7']],
            ['PROF2', 'Accounting for Business Combinations', 3, ['CORE 6', 'CORE 8']],
            ['PROF3', 'Auditing Assurance Principles', 3, ['CORE 6']],
            ['PROF4', 'Auditing and Assurance: Concepts and Applications 1', 3],
            ['PROF5', 'Auditing in a CIS Environment', 3],
            ['CORE 24', 'Accounting Research Methods', 3, ['CORE 19']],
        ]],
        [4, 1, 12, [
            ['INTERN', 'Accounting Information System Internship', 6, [], ['min_progress_percent' => 80]],
            ['RESEARCH', 'Accounting Information System Research', 3, ['CORE 24']],
            ['GE010', 'Peace Education & IP Studies/Education', 3],
        ]],
        [4, 2, 15, [
            ['PROF6', 'Auditing and Assurance: Specialized Industries', 3, ['INTERN']],
            ['PROF7', 'Accounting for Government and Non-Profit Organizations', 3, ['PROF3', 'PROF4']],
            ['PROF8', 'Auditing Assurance: Concepts and Applications 2', 3, ['PROF2']],
            ['ELEC 3', 'Operations Auditing', 3, ['INTERN']],
            ['ELEC 4', 'Valuation Concepts and Methods', 3],
        ]],
    ],
];
