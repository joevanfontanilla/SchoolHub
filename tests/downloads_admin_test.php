<?php
/**
 * The public Downloads page is kept by the Super Admin (docs/06): upload, edit, replace, hide, delete, in an order they choose, documents only.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Controllers\PublicController;
use App\Core\Database;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Models\Download;
use App\Models\User;
use App\Services\DownloadService as Downloads;

const TEST_DL = 'ZZT Test ';

/** A fixture file of a kind the public page accepts, or a fake. */
function dlFile(string $kind, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'shdl');
    register_shutdown_function(static fn () => @unlink($path));
    switch ($kind) {
        case 'pdf':
            file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
            break;
        case 'png':
            file_put_contents($path, (string) file_get_contents(fixtureFile('png')));
            break;
        case 'docx': case 'xlsx': case 'pptx':
            $folder = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$kind];
            @unlink($path);
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('[Content_Types].xml', '<Types/>');
            $zip->addFromString($folder, '<x/>');
            $zip->close();
            break;
        case 'plainzip':
            @unlink($path);
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('notes.txt', 'just a zip');
            $zip->close();
            break;
        case 'ole':
            file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 600));
            break;
        case 'text':
            file_put_contents($path, "This is only text.\n");
            break;
        case 'big':
            file_put_contents($path, "%PDF-1.4\n" . str_repeat('0', 5 * 1024 * 1024 + 10));
            break;
        case 'empty':
            file_put_contents($path, '');
            break;
    }

    return UploadedFile::forTesting($path, $name);
}

function dlCleanup(): void
{
    foreach (Database::select('SELECT id, stored_filename FROM downloads WHERE title LIKE ?', [TEST_DL . '%']) as $row) {
        @unlink(STORAGE_PATH . '/' . Downloads::DIRECTORY . '/' . $row['stored_filename']);
        Database::query('DELETE FROM downloads WHERE id = ?', [$row['id']]);
    }
    Database::query("DELETE FROM audit_logs WHERE action LIKE 'download.%' AND user_id IN (SELECT id FROM users WHERE username LIKE ?)", [testPrefixPattern()]);
    deleteTestUsers();
}

function dlInput(array $over = []): array
{
    return array_merge(['title' => TEST_DL . 'Form', 'description' => 'A form', 'sort_order' => '0', 'is_public' => '1'], $over);
}

return [
    'the Super Admin adds a file: stored under a random name, with its details, shown on the public page, and audited' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = Downloads::save($admin, null, dlInput(['title' => TEST_DL . 'Enrollment form  ', 'description' => '  The form  ']), dlFile('pdf', 'My Form (final).pdf'));
            $row = Downloads::find($id);
            $t->assertEquals(TEST_DL . 'Enrollment form', $row['title'], 'Trimmed');
            $t->assertEquals('The form', $row['description']);
            $t->assertEquals('My Form (final).pdf', $row['original_filename']);
            $t->assertEquals('application/pdf', $row['mime_type']);
            $t->assertEquals($admin->id, (int) $row['uploaded_by']);
            $t->assertTrue(preg_match('/^[0-9a-f]{32}\.pdf$/', $row['stored_filename']) === 1, 'A random stored name, never the uploaded one');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . Downloads::DIRECTORY . '/' . $row['stored_filename']), 'The file is on disk');
            $t->assertTrue(in_array($id, array_map('intval', array_column(Download::publicList(), 'id')), true), 'On the public page');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'download.created' AND user_id = ?", [$admin->id]));
        } finally {
            dlCleanup();
        }
    },

    'documents are accepted by what they really are: pdf, png, docx, xlsx, pptx, and the old doc, xls and ppt' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            foreach ([['pdf', 'a.pdf'], ['png', 'a.png'], ['docx', 'a.docx'], ['xlsx', 'a.xlsx'], ['pptx', 'a.pptx'], ['ole', 'a.doc'], ['ole', 'a.xls'], ['ole', 'a.ppt']] as [$kind, $name]) {
                $id = Downloads::save($admin, null, dlInput(['title' => TEST_DL . $name]), dlFile($kind, $name));
                $t->assertEquals(Downloads::TYPES[pathinfo($name, PATHINFO_EXTENSION)], Downloads::find($id)['mime_type'], "{$name} is stored with its own type");
            }
        } finally {
            dlCleanup();
        }
    },

    'anything that is not a real document is refused, whatever its name says' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $refused = [
                ['text', 'form.pdf', 'a text file named .pdf'],
                ['plainzip', 'form.docx', 'a zip that is not a Word file'],
                ['text', 'form.docx', 'text named .docx'],
                ['pdf', 'form.exe', 'a program'],
                ['pdf', 'form.php', 'a script'],
                ['pdf', 'form.zip', 'an archive'],
                ['pdf', 'form.html', 'a web page'],
                ['ole', 'form.pdf', 'an Office container named .pdf'],
                ['pdf', 'form.docx', 'a pdf named .docx'],
                ['text', 'form.png', 'text named .png'],
            ];
            foreach ($refused as [$kind, $name, $what]) {
                $errors = fieldErrors(static fn () => Downloads::save($admin, null, dlInput(), dlFile($kind, $name)));
                $t->assertTrue(isset($errors['file']), "{$what} is refused");
            }
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(), dlFile('big', 'big.pdf')))['file']), 'Over 5 MB');
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(), dlFile('empty', 'empty.pdf')))['file']), 'Empty');
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(), null))['file']), 'A new download needs a file');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM downloads WHERE title LIKE ?', [TEST_DL . '%']), 'Nothing was saved');
        } finally {
            dlCleanup();
        }
    },

    'the details are checked: a title is needed, and the order is a whole number' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $file = static fn () => dlFile('pdf', 'a.pdf');
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(['title' => '']), $file()))['title']));
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(['title' => str_repeat('x', 201)]), $file()))['title']));
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(['sort_order' => 'first']), $file()))['sort_order']));
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(['sort_order' => '-1']), $file()))['sort_order']));
            $t->assertTrue(isset(fieldErrors(static fn () => Downloads::save($admin, null, dlInput(['sort_order' => '10000']), $file()))['sort_order']));
            $id = Downloads::save($admin, null, dlInput(['sort_order' => '']), $file());
            $t->assertEquals(0, (int) Downloads::find($id)['sort_order'], 'Blank means 0');
        } finally {
            dlCleanup();
        }
    },

    'editing keeps the file unless a new one is given; a replacement deletes the old file' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = Downloads::save($admin, null, dlInput(), dlFile('pdf', 'old.pdf'));
            $old = Downloads::find($id);

            Downloads::save($admin, $id, dlInput(['title' => TEST_DL . 'Renamed', 'description' => '']), null);
            $kept = Downloads::find($id);
            $t->assertEquals(TEST_DL . 'Renamed', $kept['title']);
            $t->assertNull($kept['description'], 'A blank description clears it');
            $t->assertEquals($old['stored_filename'], $kept['stored_filename'], 'The file is kept');

            Downloads::save($admin, $id, dlInput(['title' => TEST_DL . 'Renamed']), dlFile('docx', 'new.docx'));
            $new = Downloads::find($id);
            $t->assertEquals('new.docx', $new['original_filename']);
            $t->assertTrue($new['stored_filename'] !== $old['stored_filename']);
            $t->assertFalse(is_file(STORAGE_PATH . '/' . Downloads::DIRECTORY . '/' . $old['stored_filename']), 'The old file is gone');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . Downloads::DIRECTORY . '/' . $new['stored_filename']), 'The new one is there');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'download.replaced' AND user_id = ?", [$admin->id]));

            // A refused replacement changes nothing.
            $errors = fieldErrors(static fn () => Downloads::save($admin, $id, dlInput(['title' => TEST_DL . 'Renamed']), dlFile('text', 'bad.pdf')));
            $t->assertTrue(isset($errors['file']));
            $t->assertEquals($new['stored_filename'], Downloads::find($id)['stored_filename']);
        } finally {
            dlCleanup();
        }
    },

    'a hidden file is off the public page and cannot be opened by its link, but the admin still has it' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = Downloads::save($admin, null, dlInput(), dlFile('pdf', 'a.pdf'));
            $publicIds = static fn () => array_map('intval', array_column(Download::publicList(), 'id'));
            $t->assertTrue(in_array($id, $publicIds(), true));

            Downloads::setPublic($admin, $id, false);
            $t->assertFalse(in_array($id, $publicIds(), true), 'Off the public list');
            $request = new Request();
            $request->setRouteParams(['id' => (string) $id]);
            $t->assertEquals(404, statusOf(static fn () => (new PublicController())->download($request)), 'The link no longer works');
            $t->assertTrue(in_array($id, array_map('intval', array_column(Downloads::all(), 'id')), true), 'The admin list still has it');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'download.hidden' AND user_id = ?", [$admin->id]));

            Downloads::setPublic($admin, $id, true);
            $t->assertTrue(in_array($id, $publicIds(), true), 'Shown again');
        } finally {
            dlCleanup();
        }
    },

    'the public page lists by the order the admin chose, newest first when equal' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $c = Downloads::save($admin, null, dlInput(['title' => TEST_DL . 'C', 'sort_order' => '5']), dlFile('pdf', 'c.pdf'));
            $a = Downloads::save($admin, null, dlInput(['title' => TEST_DL . 'A', 'sort_order' => '1']), dlFile('pdf', 'a.pdf'));
            $b = Downloads::save($admin, null, dlInput(['title' => TEST_DL . 'B', 'sort_order' => '1']), dlFile('pdf', 'b.pdf'));
            Database::update('downloads', ['created_at' => date('Y-m-d H:i:s', time() - 3600)], ['id' => $a]);
            $mine = array_values(array_filter(array_map('intval', array_column(Download::publicList(), 'id')), static fn ($id) => in_array($id, [$a, $b, $c], true)));
            $t->assertEquals([$b, $a, $c], $mine, 'Order 1 first (newest of the two first), then order 5');
        } finally {
            dlCleanup();
        }
    },

    'deleting removes the row and the file, and is audited' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = Downloads::save($admin, null, dlInput(), dlFile('pdf', 'a.pdf'));
            $path = STORAGE_PATH . '/' . Downloads::DIRECTORY . '/' . Downloads::find($id)['stored_filename'];
            Downloads::delete($admin, $id);
            $t->assertNull(Downloads::find($id));
            $t->assertFalse(is_file($path), 'The file is gone from the server');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'download.deleted' AND user_id = ?", [$admin->id]));
            $t->assertEquals(404, statusOf(static fn () => Downloads::delete($admin, $id)), 'Gone is gone');
        } finally {
            dlCleanup();
        }
    },

    'only the Super Admin can manage downloads, and every route is behind settings.manage' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = Downloads::save($admin, null, dlInput(), dlFile('pdf', 'a.pdf'));
            foreach ([User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_TEACHER, User::ROLE_STUDENT] as $role) {
                $other = makeUser($role);
                $t->assertEquals(403, statusOf(static fn () => Downloads::save($other, null, dlInput(), dlFile('pdf', 'x.pdf'))), "{$role} cannot add");
                $t->assertEquals(403, statusOf(static fn () => Downloads::setPublic($other, $id, false)), "{$role} cannot hide");
                $t->assertEquals(403, statusOf(static fn () => Downloads::delete($other, $id)), "{$role} cannot delete");
            }
            $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');
            preg_match_all("#router->(?:get|post)\\('/admin/downloads[^']*',[^\\n]*#", $routes, $m);
            $t->assertTrue(count($m[0]) >= 8, 'The eight routes exist');
            foreach ($m[0] as $line) {
                $t->assertContains("admin('settings.manage')", $line);
            }
            $t->assertContains('Downloads', (string) file_get_contents(BASE_PATH . '/app/Helpers/navigation.php'));
            $t->assertContains('Administration > Downloads', (string) file_get_contents(BASE_PATH . '/docs/06_PUBLIC_WEBSITE.md'));
        } finally {
            dlCleanup();
        }
    },
];
