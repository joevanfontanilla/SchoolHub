<?php
/**
 * Bachelor of Science in Criminology, effective SY 2022-2023
 * (CMO No. 5 s. 2018 and CMO No. 4 s. 2018).
 *
 * Transcribed from "Criminology Curiculum.pdf" (prepared by the Registrar, noted
 * by the Dean of Criminology, approved by the President and CEO). Format: docs/28.
 *
 * This is the only curriculum that prints weekly lecture | laboratory hours, so
 * they are recorded. A laboratory hour counts as one of the subject's units here
 * (2 | 1 hours = 3 units), so lab_units follows the lab hours.
 */

$lab = ['lecture_hours' => 2, 'lab_hours' => 1, 'lab_units' => 1];
$lecture = static fn (int $hours): array => ['lecture_hours' => $hours];

// "All professional courses" (the Review's prerequisite): every Criminology,
// Criminal Law and Jurisprudence, Crime Detection and Investigation, Law
// Enforcement Administration, Correctional Administration, and Forensic subject
// taken before the final term.
$professional = array_merge(
    array_map(static fn ($n) => "CRIMINOLOGY {$n}", range(1, 7)),
    array_map(static fn ($n) => "CLJ {$n}", range(1, 6)),
    array_map(static fn ($n) => "CDI {$n}", range(1, 9)),
    array_map(static fn ($n) => "LEA {$n}", range(1, 4)),
    array_map(static fn ($n) => "CA {$n}", range(1, 3)),
    array_map(static fn ($n) => "FORENSIC {$n}", range(1, 6)),
);

return [
    'program'               => 'BSCRIM',
    'code'                  => 'BSCRIM-2022',
    'name'                  => 'Bachelor of Science in Criminology',
    'effective_school_year' => '2022-2023',
    'notes'                 => 'CMO No. 5 s. 2018 and CMO No. 4 s. 2018',

    'to_confirm' => [
        '3rd year, 1st semester: the listed subjects total 24 units (23 lecture | 1 lab hours), but the printed total is 26 (24 | 2). A 2-unit subject may be missing from the PDF.',
        'Panitikan is coded FL3; the other Filipino subjects are FIL 1 and FIL 2. Imported as printed.',
        'PED4 (Fundamentals of Marksmanship) has no prerequisite, while PED2 and PED3 each require the one before. Imported as printed.',
        'The Review requires "All professional courses". Imported as every CRIMINOLOGY, CLJ, CDI, LEA, CA, and FORENSIC subject from the earlier terms.',
    ],

    'terms' => [
        [1, 1, 26, [
            ['CRIMINOLOGY 1', 'Introduction to Criminology', 3, [], $lecture(3)],
            ['GE001', 'Understanding Self', 3, [], $lecture(3)],
            ['GE002', 'Purposive Communication', 3, [], $lecture(3)],
            ['GE003', 'Mathematics in the Modern World', 3, [], $lecture(3)],
            ['GE004', 'Readings in Philippine History', 3, [], $lecture(3)],
            ['CHRIS1', 'Christian Values', 3, [], $lecture(3)],
            ['EPC', 'English Proficiency Course', 3, [], $lecture(3)],
            ['PED1', 'Fundamentals of Martial Arts', 2, [], $lecture(2)],
            ['NSTP 01', 'National Service Training Program 1', 3, [], $lecture(3)],
        ]],
        [1, 2, 29, [
            ['CLJ 1', 'Philippine Criminal Justice System', 3, [], $lecture(3)],
            ['CFLM 1', 'Character Formation, Nationalism and Patriotism', 3, [], $lecture(3)],
            ['CRIMINOLOGY 2', 'Theories of Crime Causation', 3, ['CRIMINOLOGY 1'], $lecture(3)],
            ['GE005', 'Life and Works of Rizal', 3, [], $lecture(3)],
            ['GE006', 'Science, Technology and Society', 3, [], $lecture(3)],
            ['GE007', 'The Contemporary World', 3, [], $lecture(3)],
            ['FIL 1', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, [], $lecture(3)],
            ['LIT 1', 'Philippine Literature in English', 3, [], $lecture(3)],
            ['PED2', 'Arnis & Disarming Techniques', 2, ['PED1'], $lecture(2)],
            ['NSTP 02', 'National Service Training Program 2', 3, ['NSTP 01'], $lecture(3)],
        ]],
        [2, 1, 28, [
            ['CDI 1', 'Fundamentals of Criminal Investigation and Intelligence', 4, [], $lecture(4)],
            ['LEA 1', 'Law Enforcement Organization and Administration', 4, [], $lecture(4)],
            ['LEA 2', 'Comparative Models in Policing', 3, [], $lecture(3)],
            ['CA 1', 'Institutional Corrections', 3, [], $lecture(3)],
            ['GE008', 'Art Appreciation', 3, [], $lecture(3)],
            ['GE009', 'Ethics', 3, [], $lecture(3)],
            ['FIL 2', "Filipino sa Iba't-Ibang Disiplina", 3, [], $lecture(3)],
            ['LIT 2', 'World Literature', 3, [], $lecture(3)],
            ['PED3', 'First Aid and Water Survival', 2, ['PED2'], $lecture(2)],
        ]],
        [2, 2, 29, [
            ['CFLM 2', 'Character Formation with Leadership, Decision Making, Management and Administration', 3, ['CFLM 1'], $lecture(3)],
            ['FORENSIC 1', 'Forensic Photography', 3, [], $lab],
            ['CDI 2', 'Specialized Crime Investigation 1 w/ Legal Medicine', 3, ['CDI 1'], $lecture(3)],
            ['CHEM', 'General Chemistry (Organic)', 3, [], $lab],
            ['FORENSIC 2', 'Personal Identification Techniques', 3, [], $lab],
            ['CRIMINOLOGY 3', 'Human Behavior & Victimology', 3, ['CRIMINOLOGY 2'], $lecture(3)],
            ['LEA 3', 'Introduction to Industrial Security Concepts', 3, [], $lecture(3)],
            ['FL3', 'Panitikan', 3, [], $lecture(3)],
            ['CDI 3', 'Traffic Management and Accident Investigation w/ Driving', 3, ['CDI 1'], $lecture(3)],
            ['PED4', 'Fundamentals of Marksmanship', 2, [], $lecture(2)],
        ]],
        [2, 3, 9, [
            ['CLJ 2', 'Human Rights Education', 3],
            ['LEA 4', 'Law Enforcement Operations and Planning w/ Crime Mapping', 3, ['LEA 1']],
            ['CDI 4', 'Fire Protection and Arson Investigation', 3, ['CDI 1']],
        ]],
        [3, 1, 26, [
            ['CA 2', 'Non-Institutional Corrections', 3, ['CA 1'], $lecture(3)],
            ['CDI 5', 'Special Crime Investigation 2', 3, ['CDI 2'], $lecture(3)],
            ['FORENSIC 3', 'Forensic Chemistry and Toxicology', 3, ['FORENSIC 1', 'FORENSIC 2'], $lab],
            ['CLJ 3', 'Criminal Law (Book 1)', 3, ['CLJ 1'], $lecture(3)],
            ['CDI 6', 'Technical English 1 (Investigative Report Writing and Presentation)', 3, ['CDI 1'], $lecture(3)],
            ['CRIMINOLOGY 4', 'Professional Conduct and Ethical Standards', 3, ['CRIMINOLOGY 1'], $lecture(3)],
            ['CRIMINOLOGY 5', 'Juvenile Delinquency and Juvenile Justice System', 3, ['CRIMINOLOGY 2'], $lecture(3)],
            ['CRIMINOLOGY 6', 'Dispute Resolution and Crises/Incidents Management', 3, ['CRIMINOLOGY 3'], $lecture(3)],
        ]],
        [3, 2, 25, [
            ['FORENSIC 4', 'Questioned Documents Examination', 3, [], $lab],
            ['FORENSIC 5', 'Lie Detection Techniques', 3, [], $lab],
            ['CDI 7', 'Vice and Drug Education and Control', 3, ['CDI 1'], $lecture(3)],
            ['CLJ 4', 'Criminal Law (Book 2)', 4, ['CLJ 3'], $lecture(4)],
            ['CDI 8', 'Technical English 2 (Legal Forms)', 3, ['CDI 6'], $lecture(3)],
            ['FORENSIC 6', 'Forensic Ballistics', 3, [], $lab],
            ['CA 3', 'Therapeutic Modalities', 3, ['CA 2'], $lecture(3)],
            ['CDI 9', 'Introduction to Cybercrime and Environmental Laws and Protection', 3, ['CDI 5'], $lecture(3)],
        ]],
        [4, 1, 12, [
            ['PRACTICUM 1', 'Internship (OJT)', 3, [], $lecture(3) + ['description' => '270 field hours.']],
            ['CLJ 5', 'Evidence', 3, ['CLJ 4'], $lecture(3)],
            ['CRIMINOLOGY 7', 'Criminological Research 1', 3, [], $lecture(3)],
            ['CLJ 6', 'Criminal Procedure and Court Testimony', 3, [], $lecture(3)],
        ]],
        [4, 2, 12, [
            ['PRACTICUM 2', 'Internship (OJT)', 3, ['PRACTICUM 1'], $lecture(3) + ['description' => '270 field hours.']],
            ['REVIEW', 'Professional Practice & Review with Comprehensive Examination', 6, $professional, $lecture(6)],
            ['CRIMINOLOGY 8', 'Criminological Research 2', 3, ['CRIMINOLOGY 7'], $lecture(3)],
        ]],
    ],
];
