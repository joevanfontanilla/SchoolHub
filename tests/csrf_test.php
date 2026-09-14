<?php
/**
 * CSRF protection (docs/07, docs/22).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Csrf;
use App\Middleware\VerifyCsrf;

return [
    'a POST without a token is rejected' => function (TestRunner $t): void {
        $_SESSION = [];
        Csrf::token(); // establish a token for the session

        $status = statusOf(static function (): void {
            (new VerifyCsrf())->handle(makeRequest('POST', '/login', ['login' => 'someone']));
        });

        $t->assertEquals(403, $status, 'A POST with no token must be refused');
    },

    'a POST with the wrong token is rejected' => function (TestRunner $t): void {
        $_SESSION = [];
        Csrf::token();

        $status = statusOf(static function (): void {
            (new VerifyCsrf())->handle(makeRequest('POST', '/login', ['_token' => 'not-the-right-token']));
        });

        $t->assertEquals(403, $status, 'A POST with a forged token must be refused');
    },

    'a POST with the correct token passes' => function (TestRunner $t): void {
        $_SESSION = [];
        $token = Csrf::token();

        $status = statusOf(static function () use ($token): void {
            (new VerifyCsrf())->handle(makeRequest('POST', '/login', ['_token' => $token]));
        });

        $t->assertNull($status, 'A POST carrying the session token must pass');
    },

    'a GET is not blocked by the CSRF check' => function (TestRunner $t): void {
        $_SESSION = [];
        Csrf::token();

        $status = statusOf(static function (): void {
            (new VerifyCsrf())->handle(makeRequest('GET', '/'));
        });

        $t->assertNull($status, 'A GET is not state-changing and needs no token');
    },

    'rotating the token invalidates the old one' => function (TestRunner $t): void {
        $_SESSION = [];
        $old = Csrf::token();
        Csrf::rotate();

        $t->assertFalse(Csrf::verify($old), 'The pre-rotation token must stop working');
        $t->assertTrue(Csrf::verify(Csrf::token()), 'The new token must work');
    },

    'an empty token never verifies' => function (TestRunner $t): void {
        $_SESSION = [];
        Csrf::token();

        $t->assertFalse(Csrf::verify(''), 'An empty string must not verify');
        $t->assertFalse(Csrf::verify(null), 'A missing token must not verify');
    },
];
