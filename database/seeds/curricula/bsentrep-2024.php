<?php
/**
 * Bachelor of Science in Entrepreneurship, effective SY 2024-2025.
 *
 * Transcribed from "Entrepreneurship Curriculum.pdf" (prepared by the Registrar,
 * noted by the President and CEO, contents noted 7-23-24). Format: docs/28.
 */

return [
    'program'               => 'BSENTREP',
    'code'                  => 'BSENTREP-2024',
    'name'                  => 'Bachelor of Science in Entrepreneurship',
    'effective_school_year' => '2024-2025',
    'notes'                 => null,

    'to_confirm' => [
        'PATH-FIT 4 requires PATH-FIT 2 here; every other program requires PATH-FIT 3. Imported as printed.',
        'Christian Values is coded CHRIST here and CHRIS1 in BSA, BSAIS, BSCrim, and BPA. Imported as printed.',
    ],

    'terms' => [
        [1, 1, 23, [
            ['GE001', 'Understanding Self', 3],
            ['GE002', 'Purposive Communication', 3],
            ['GE003', 'Mathematics in the Modern World', 3],
            ['GE004', 'Readings in Philippine History', 3],
            ['GE ELEC 1', 'Environmental Science', 3],
            ['CHRIST', 'Christian Values', 3],
            ['PATH-FIT 1', 'Movement Enhancement', 2],
            ['NSTP 01', 'National Service Training Program 1', 3],
        ]],
        [1, 2, 23, [
            ['GE005', 'Art Appreciation', 3],
            ['GE006', 'Science, Technology and Society', 3],
            ['GE007', 'The Contemporary World', 3],
            ['GE008', 'Life and Works of Rizal', 3],
            ['EB', 'Entrepreneurial Behavior', 3],
            ['ACC101', 'Basic Accounting', 3],
            ['PATH-FIT 2', 'Exercise-based Fitness Activities', 2, ['PATH-FIT 1']],
            ['NSTP 02', 'National Service Training Program 2', 3, ['NSTP 01']],
        ]],
        [2, 1, 20, [
            ['GE009', 'Ethics', 3],
            ['GE ELEC 2', 'Gender & Society', 3],
            ['OPP', 'Opportunity Seeking', 3, ['EB']],
            ['PPED', "Programs & Policies on Enterprise Dev't", 3],
            ['ELEC 1', 'Entrepreneurial Leadership in an Organization', 3, ['EB']],
            ['ECO 1', 'Microeconomics', 3],
            ['PATH-FIT 3', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 2']],
        ]],
        [2, 2, 20, [
            ['IBT', 'International Business & Trade', 3],
            ['PC', 'Pricing and Costing', 3, ['EB']],
            ['POM', 'Production & Operations Management', 3],
            ['BUS102', 'Business Law & Taxation (with focus on Laws affecting micro and SMEs)', 3, ['PPED']],
            ['ELEC 2', 'Franchising', 3, ['EB']],
            ['MRCB', 'Market Research & Consumer Behavior', 3, ['OPP']],
            ['PATH-FIT 4', 'Menu of Dance, Sports, Group Exercise, Outdoor & Adventure Activities', 2, ['PATH-FIT 2']],
        ]],
        [2, 3, 9, [
            ['BOOKKEEPING', 'Bookkeeping', 3, [], ['description' => "Students are required to undergo an assessment to determine their ability to meet the workplace's competency standards."]],
            ['GE ELEC 3', 'Living in the IT Era', 3],
            ['ELEC 3', 'Events Management', 3],
        ]],
        [3, 1, 15, [
            ['FM', 'Financial Management (Financial Analysis for Decision Making)', 3, ['PC']],
            ['HRM', 'Human Resources Management', 3, ['ELEC 1']],
            ['SE', 'Social Entrepreneurship', 3],
            ['TRACK 1', 'Agribusiness', 3],
            ['SCM', 'Introduction to Supply Chain Management', 3, ['POM']],
        ]],
        [3, 2, 15, [
            ['BPP', 'Business Plan Preparation', 3, ['PPED']],
            ['IM', 'Innovation Management', 3, ['MRCB']],
            ['SM', 'Strategic Management', 3],
            ['TRACK 2', 'Microfinance', 3],
            ['ELEC 4', 'E-Commerce', 3],
        ]],
        [4, 1, 11, [
            ['BPLAN1', 'Business Plan Implementation 1', 5, ['BPP'], ['lab_units' => 3]],
            ['TRACK 3', 'Tourism Business', 3],
            ['GE010', 'Peace Education & IP Studies / Education', 3],
        ]],
        [4, 2, 8, [
            ['BPLAN2', 'Business Plan Implementation 2', 5, ['BPLAN1'], ['lab_units' => 3]],
            ['TRACK 4', 'Culinary', 3],
        ]],
    ],
];
