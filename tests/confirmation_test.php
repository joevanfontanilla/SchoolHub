<?php
/**
 * Every button that makes a decision asks first, in the shared confirmation window
 * (docs/21). This reads the views, so a new Reject or Delete button without
 * data-confirm fails here.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

return [
    'every deciding button is covered by a confirmation, and a required reason names a real field' => function (TestRunner $t): void {
        // Reversible housekeeping that deliberately does not ask.
        $exempt = [
            'app/Views/portal/notifications/index.php', // archive / move to inbox
            'app/Views/admin/backups/index.php:Verify', // a read-only checksum check
        ];
        $decision = '/\b(approve|reject|delete|disable|enable|reverse|drop|cancel|archive|restore|publish|post|unlock|override|verify|confirm|return|correction|enlist|adjustment|logout|sign out)\b/i';
        $checked = 0;
        $missing = [];
        $badRequires = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen(BASE_PATH) + 1);
            $source = (string) file_get_contents($file->getPathname());
            // Blank out PHP blocks (same length) so a PHP closing tag is never read as the end of an HTML tag.
            $masked = (string) preg_replace_callback('/<\?(?:php|=).*?\?>/s', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $source);

            preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $masked, $buttons, PREG_OFFSET_CAPTURE);
            foreach ($buttons[0] as $i => [$whole, $offset]) {
                if (!str_contains($buttons[1][$i][0], 'type="submit"')) {
                    continue;
                }
                $label = trim((string) preg_replace('/\s+/', ' ', strip_tags(substr($source, $buttons[2][$i][1], strlen($buttons[2][$i][0])))));
                if (!preg_match($decision, $label) || in_array($path, $exempt, true)) {
                    continue;
                }
                $word = trim(strip_tags((string) preg_replace('/<\?(?:php|=).*?\?>/s', '', $label)));
                if (in_array($path . ':' . $word, $exempt, true)) {
                    continue;
                }
                $formStart = strrpos(substr($masked, 0, $offset), '<form');
                $formTag = $formStart === false ? '' : substr($masked, $formStart, strpos($masked, '>', $formStart) - $formStart);
                $checked++;
                if (!str_contains($buttons[1][$i][0], 'data-confirm-click=') && !str_contains($formTag, 'data-confirm=')) {
                    $missing[] = $path . ' [' . $word . ']';
                }
            }

            // A required reason must point at an id selector that exists in the same view.
            preg_match_all('/data-confirm-requires="#([A-Za-z][\w-]*)/', $source, $requires);
            foreach ($requires[1] as $id) {
                // The field component builds its id as "field-" plus the name.
                $fieldName = str_starts_with($id, 'field-') ? "'name' => '" . substr($id, 6) . "'" : null;
                if (!preg_match('/id="' . preg_quote($id, '/') . '/', $source) && ($fieldName === null || !str_contains($source, $fieldName))) {
                    $badRequires[] = $path . ' #' . $id;
                }
            }
        }

        $t->assertTrue($checked >= 20, "Found the deciding buttons ($checked)");
        $t->assertEquals([], $missing, 'Deciding buttons without a confirmation');
        $t->assertEquals([], $badRequires, 'Required-reason selectors with no matching field');
    },
];
