<?php
/**
 * Server-side validation and output escaping (docs/22).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Validator;

return [
    'required rejects empty values' => function (TestRunner $t): void {
        $v = Validator::make(['name' => '', 'other' => null], [
            'name'  => 'required',
            'other' => 'required',
        ]);

        $t->assertTrue($v->fails());
        $t->assertEquals(2, count($v->errors()));
    },

    'email format is enforced' => function (TestRunner $t): void {
        $t->assertTrue(Validator::make(['email' => 'not-an-email'], ['email' => 'required|email'])->fails());
        $t->assertTrue(Validator::make(['email' => 'someone@example.com'], ['email' => 'required|email'])->passes());
    },

    'min and max lengths are enforced' => function (TestRunner $t): void {
        $t->assertTrue(Validator::make(['p' => 'abc'], ['p' => 'min:8'])->fails(), 'Too short must fail');
        $t->assertTrue(Validator::make(['p' => str_repeat('a', 200)], ['p' => 'max:150'])->fails(), 'Too long must fail');
        $t->assertTrue(Validator::make(['p' => 'abcdefgh'], ['p' => 'min:8|max:150'])->passes());
    },

    'confirmed compares against the _confirmation field' => function (TestRunner $t): void {
        $t->assertTrue(
            Validator::make(
                ['password' => 'Secret123', 'password_confirmation' => 'Different1'],
                ['password' => 'required|confirmed']
            )->fails(),
            'A mismatched confirmation must fail'
        );

        $t->assertTrue(
            Validator::make(
                ['password' => 'Secret123', 'password_confirmation' => 'Secret123'],
                ['password' => 'required|confirmed']
            )->passes()
        );
    },

    'numeric and integer are enforced' => function (TestRunner $t): void {
        $t->assertTrue(Validator::make(['n' => 'abc'], ['n' => 'numeric'])->fails());
        $t->assertTrue(Validator::make(['n' => '12.5'], ['n' => 'numeric'])->passes());
        $t->assertTrue(Validator::make(['n' => '12.5'], ['n' => 'integer'])->fails(), 'A decimal is not an integer');
        $t->assertTrue(Validator::make(['n' => '12'], ['n' => 'integer'])->passes());
    },

    'in restricts a value to an allowed set' => function (TestRunner $t): void {
        $rules = ['status' => 'required|in:DRAFT,SUBMITTED,APPROVED'];

        $t->assertTrue(Validator::make(['status' => 'DELETED'], $rules)->fails(), 'A value outside the set must fail');
        $t->assertTrue(Validator::make(['status' => 'SUBMITTED'], $rules)->passes());
    },

    'nullable skips the remaining rules when the value is empty' => function (TestRunner $t): void {
        $t->assertTrue(Validator::make(['middle_name' => ''], ['middle_name' => 'nullable|min:2'])->passes());
        $t->assertTrue(Validator::make(['middle_name' => 'a'], ['middle_name' => 'nullable|min:2'])->fails());
    },

    'error messages name the field' => function (TestRunner $t): void {
        $v = Validator::make(['login' => ''], ['login' => 'required'], ['login' => 'Email or username']);

        $t->assertTrue($v->fails());
        $t->assertContains('Email or username', $v->errors()['login'][0]);
    },

    'e() escapes script tags' => function (TestRunner $t): void {
        $escaped = e('<script>alert("xss")</script>');

        $t->assertFalse(str_contains($escaped, '<script>'), 'The tag must be escaped');
        $t->assertContains('&lt;script&gt;', $escaped);
    },

    'e() escapes quotes so attributes cannot be broken out of' => function (TestRunner $t): void {
        $escaped = e('" onerror="alert(1)');

        $t->assertFalse(str_contains($escaped, '"'), 'Double quotes must be escaped');
    },

    'url() keeps the application base path' => function (TestRunner $t): void {
        // A leading-slash literal would break under a subdirectory install —
        // url() is what prevents that (see CLAUDE.md).
        $t->assertContains('/login', url('/login'));
        $t->assertEquals(url('/login'), url('login'), 'Leading slash should not matter');
    },

    'the file streamer strips paths out of a download name' => function (TestRunner $t): void {
        $t->assertEquals('passwd', App\Services\FileStreamer::sanitizeFilename('../../../etc/passwd'));
        $t->assertEquals('report.pdf', App\Services\FileStreamer::sanitizeFilename('C:\\Windows\\report.pdf'));
    },

    'url() never produces a protocol-relative or backslashed path' => function (TestRunner $t): void {
        // Regression: on Windows, dirname('/index.php') returns a BACKSLASH.
        // That made the base path '\' and every asset URL '//assets/...',
        // which the browser reads as protocol-relative and sends to a host
        // literally named "assets" — so no stylesheet ever loaded.
        foreach (['assets/app.css', '/assets/app.css', '/login'] as $path) {
            $link = url($path);

            $t->assertFalse(str_starts_with($link, '//'), "'{$path}' must not become protocol-relative (got '{$link}')");
            $t->assertFalse(str_contains($link, "\\"), "'{$path}' must contain no backslash (got '{$link}')");
        }
    },
];