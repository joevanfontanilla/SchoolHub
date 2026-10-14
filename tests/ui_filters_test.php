<?php
/**
 * Filter forms apply by themselves (docs/21): every GET form in the views carries data-auto-submit, which
 * public/assets/app.js turns into "no button to click". The script itself is exercised in the browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

/** @return array<int,array{file:string,tag:string}> every <form ... method="GET"> in the views */
function getForms(): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        // Blank out the PHP inside the markup first: its closing tag would otherwise end a form tag early.
        $markup = preg_replace('/<\?.*?\?>/s', 'PHP', (string) file_get_contents($file->getPathname()));
        if (preg_match_all('/<form\b[^>]*\bmethod="GET"[^>]*>/is', $markup, $matches)) {
            foreach ($matches[0] as $tag) {
                $found[] = ['file' => str_replace(BASE_PATH . '/', '', str_replace('\\', '/', $file->getPathname())), 'tag' => $tag];
            }
        }
    }

    return $found;
}

return [
    'every filter form applies by itself: data-auto-submit on each GET form, except search and lookup forms (role="search"), which wait for Enter' => function (TestRunner $t): void {
        $forms = getForms();
        $t->assertTrue(count($forms) >= 25, 'Found the filter forms (' . count($forms) . ')');

        $missing = [];
        foreach ($forms as $form) {
            $navigation = str_contains($form['tag'], 'role="search"');
            if (!$navigation && !str_contains($form['tag'], 'data-auto-submit')) {
                $missing[] = $form['file'];
            }
        }
        $t->assertEquals([], $missing, 'Every filter form needs data-auto-submit (docs/21)');
    },

    'the script behind it applies dropdowns, dates, and search by itself, and keeps the range rule' => function (TestRunner $t): void {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/app.js');

        $t->assertContains("form[data-auto-submit]", $js, 'The script looks for the attribute');
        $t->assertContains("'select, input[type=\"date\"], input[type=\"month\"], input[type=\"checkbox\"], input[type=\"radio\"]'", $js, 'Dropdowns, dates, and checkboxes apply on change');
        $t->assertContains('input[type="search"], input[type="text"], input[type="number"]', $js, 'Search and number boxes apply after a pause');
        $t->assertContains('The start date must be on or before the end date.', $js, 'A reversed date range waits instead of loading an error');
        $t->assertContains('button.hidden = true', $js, 'The Show and Search buttons are hidden while scripts work');
    },
];
