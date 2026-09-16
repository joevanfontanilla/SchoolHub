<?php
/**
 * Bachelor of Public Administration, effective SY 2024-2025.
 *
 * Transcribed from "Public Administration Curriculum.pdf" (prepared by the
 * Registrar, noted by the President and CEO). Format: docs/28.
 *
 * The PDF writes the major codes both "PUB AD 1" and "Pub Ad 2"; codes are stored
 * upper-case, so all are PUB AD n.
 */

return [
    'program'               => 'BPA',
    'code'                  => 'BPA-2024',
    'name'                  => 'Bachelor of Public Administration',
    'effective_school_year' => '2024-2025',
    'notes'                 => null,

    'to_confirm' => [
        'SOSCI 3 (Macroeconomics) requires SOSCI 2, which is not in this curriculum; it may mean ECO 1 (Microeconomics). Imported with no prerequisite until confirmed.',
        'GE008 is Ethics and GE009 is Life and Works of Rizal here, the reverse of the other programs. Imported as printed.',
        'Bookkeeping is NC II here and NC III in BSA and BSAIS. Imported as printed.',
    ],

    'terms' => [
        [1, 1, 26, [
            ['GE001', 'Understanding Self', 3],
            ['GE002', 'Purposive Communication', 3],
            ['GE003', 'Mathematics in the Modern World', 3],
            ['GE004', 'Readings in Philippine History', 3],
            ['GELE01', 'Environmental Science', 3],
            ['PUB AD 1', 'Intro to Public Administration 1', 3],
            ['CHRIS1', 'Christian Values', 3],
            ['PATH-FIT 1', 'Movement Enhancement', 2],
            ['NSTP 01', 'National Service Training Program 1', 3],
        ]],
        [1, 2, 23, [
            ['GE005', 'Art Appreciation', 3],
            ['GE006', 'Science, Technology and Society', 3],
            ['GE007', 'The Contemporary World', 3],
            ['PUB AD 2', 'Knowledge Management and ICT for PA', 3, ['PUB AD 1']],
            ['PUB AD 3', 'Human Behavior in Organization', 3],
            ['CORE 1', 'Basic Accounting', 3],
            ['PATH-FIT 2', 'Exercise-based Fitness Activities', 2, ['PATH-FIT 1']],
            ['NSTP 02', 'National Service Training Program 2', 3, ['NSTP 01']],
        ]],
        [2, 1, 23, [
            ['GELE02', 'Gender & Society', 3],
            ['GELE03', 'Living in the IT Era', 3],
            ['CORE 2', 'Fundamentals of Statistics', 3, ['CORE 1']],
            ['PUB AD 4', 'Organization Management', 3, ['PUB AD 3']],
            ['PUB AD 5', 'Phil. Administrative Thought & Institution', 3, ['PUB AD 1']],
            ['ECO 1', 'Microeconomics', 3],
            ['TAX 1', 'Income Taxation', 3],
            ['PATH-FIT 3', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 2']],
        ]],
        [2, 2, 23, [
            ['PUB AD 6', 'Office and System Management', 3, ['PUB AD 3']],
            ['ELEC 1', 'Records and Property Management', 3],
            ['ELEC 2', 'Human Resource Management in Public Sector', 3, ['PUB AD 4']],
            ['CORE 3', 'Good Governance and Social Responsibility', 3],
            ['CORE 4', 'Sociology', 3],
            ['SOSCI 3', 'Macroeconomics', 3],
            ['POM', 'Production & Operations Management', 3],
            ['PATH-FIT 4', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 3']],
        ]],
        [2, 3, 9, [
            ['BOOKKEEPING', 'Bookkeeping NC II', 3, [], ['description' => "Students are required to undergo an assessment to determine their ability to meet the workplace's competency standards."]],
            ['GE008', 'Ethics', 3],
            ['GE009', 'Life and Works of Rizal', 3],
        ]],
        [3, 1, 21, [
            ['PUB AD 7', 'Public Accounting and Budgeting', 3],
            ['PUB AD 8', 'Local and Regional Governance', 3, ['PUB AD 2']],
            ['PUB AD 9', 'Public Personnel Administration', 3],
            ['PUB AD 10', 'Ethics and Accountability in the Public Service', 3, ['PUB AD 1', 'PUB AD 5']],
            ['PUB AD 11', 'Administrative Law', 3],
            ['PUB AD 12', 'Public Policy and Program Administration', 3],
            ['STRAT', 'Introduction to Strategic Mgt.', 3],
        ]],
        [3, 2, 15, [
            ['PUB AD 13', 'Politics and Administration', 3, ['PUB AD 12']],
            ['PUB AD 14', 'Public Fiscal Administration', 3, ['PUB AD 1']],
            ['PUB AD 15', 'Research Method 1 applied in BPA', 3, ['CORE 2', 'PUB AD 7', 'PUB AD 9', 'PUB AD 12']],
            ['PUB AD 16', 'Leadership and Decision Making', 3, ['PUB AD 7']],
            ['ELEC 3', 'Project Development and Management', 3, ['PUB AD 12']],
        ]],
        [4, 1, 9, [
            ['INTERN', 'Practicum', 6, [], ['min_progress_percent' => 80]],
            ['GE010', 'Peace Education & IP Studies/Education', 3],
        ]],
        [4, 2, 12, [
            ['PUB AD 17', 'Research Method 2 (BPA thesis writing)', 3, ['PUB AD 15']],
            ['PUB AD 18', 'Special Topics in Public Administration with Seminars', 3, ['PUB AD 13']],
            ['PUB AD 19', 'Governance and Development', 3, ['PUB AD 13', 'ELEC 3']],
            ['ELEC 4', 'Environmental Management', 3],
        ]],
    ],
];
