<?php
/**
 * Bachelor of Science in Accounting Information System, effective SY 2024-2025.
 *
 * Transcribed from "Accounting Information System Curiculum.pdf" (prepared by
 * the Registrar, noted by the President and CEO, contents noted 07-23-2024).
 * Format: docs/28.
 */

return [
    'program'               => 'BSAIS',
    'code'                  => 'BSAIS-2024',
    'name'                  => 'Bachelor of Science in Accounting Information System',
    'effective_school_year' => '2024-2025',
    'notes'                 => null,

    'to_confirm' => [
        'The PDF codes both Environmental Science (1st year) and Peace Education (4th year) as GE010. Imported Environmental Science as GELEC1, next to GELEC2 and GELEC3, and kept GE010 for Peace Education as the other programs do.',
        'The PDF uses PRELEC for two different 4th-year subjects. Imported Business Analytics as PRELEC 1 and Financial Modeling as PRELEC 2.',
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
            ['POM', 'Operation Management & TQM', 3],
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
        [3, 1, 18, [
            ['CORE 19', 'Statistical Analysis with Software Application', 3],
            ['CORE 20', 'Financial Markets', 3, ['CORE 17']],
            ['CORE 21', 'International Business and Trade', 3],
            ['CORE 22', 'Strategic Business Analysis', 3, ['CORE 18', 'CORE 16']],
            ['CORE 23', 'Management Science', 3],
            ['STRAT', 'Strategic Management', 3],
        ]],
        [3, 2, 18, [
            ['PROF 1', 'Managing Information System', 3, ['CORE 16']],
            ['PROF 2', 'Information Systems Operations and Maintenance', 3, ['CORE 16']],
            ['PROF 3', 'Information Security and Management', 3, ['CORE 16']],
            ['PROF 4', 'Information System Analysis and Design', 3, ['CORE 16']],
            ['PROF 5', 'Data Warehousing and Management', 3, ['CORE 9']],
            ['CORE 24', 'Accounting Research Methods', 3, ['CORE 19']],
        ]],
        [4, 1, 12, [
            ['INTERN', 'Accounting Information System Internship', 6, [], ['min_progress_percent' => 80]],
            ['RESEARCH', 'Accounting Information System Research', 3, ['CORE 22']],
            ['GE010', 'Peace Education & IP Studies/Education', 3],
        ]],
        [4, 2, 15, [
            ['PROF 6', 'Project Management', 3, ['STRAT', 'CORE 19']],
            ['PROF 7', 'Managing Information and Technology', 3, ['CORE 6']],
            ['PROF 8', 'Enterprise Resource Planning and Management', 3, ['CORE 16']],
            ['PRELEC 1', 'Business Analytics', 3, ['CORE 8', 'CORE 11', 'CORE 19']],
            ['PRELEC 2', 'Financial Modeling', 3],
        ]],
    ],
];
