<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\Admin\EditionFilesZipExporter;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;
use WP_User;

class EditionFilesZipExporterTest extends TestCase
{
    private EditionFilesZipExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new EditionFilesZipExporter(
            $this->createMock(EditionService::class),
            $this->createMock(EditionRepository::class),
            $this->createMock(RegistrationRepository::class),
        );
    }

    public function testBuildFileNamePrefixesLastnameFirstname(): void
    {
        $user = $this->makeUser(['first_name' => 'Marie', 'last_name' => 'Janssens']);
        self::assertSame(
            'janssens-marie-vragenlijst-attest-leerkracht.pdf',
            $this->exporter->buildFileName($user, 'questionnaire', 'attest-leerkracht.pdf'),
        );
    }

    public function testBuildFileNameFallsBackToUserIdWhenNamesEmpty(): void
    {
        $user = $this->makeUser(['ID' => 42, 'first_name' => '', 'last_name' => '']);
        self::assertSame(
            'user-42-documenten-id.pdf',
            $this->exporter->buildFileName($user, 'documents', 'id.pdf'),
        );
    }

    public function testDutchTaskKeyMaps(): void
    {
        self::assertSame('vragenlijst', $this->exporter->dutchTaskKey('questionnaire'));
        self::assertSame('documenten', $this->exporter->dutchTaskKey('documents'));
        self::assertSame('post-documenten', $this->exporter->dutchTaskKey('post_documents'));
        self::assertSame('unknown-key', $this->exporter->dutchTaskKey('unknown_key'));
    }

    public function testResolveCollisionAppendsCounter(): void
    {
        $used = [];
        $a = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');
        $used[$a] = true;
        $b = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');
        $used[$b] = true;
        $c = $this->exporter->resolveCollision($used, 'janssens-marie-vragenlijst-attest.pdf');

        self::assertSame('janssens-marie-vragenlijst-attest.pdf', $a);
        self::assertSame('janssens-marie-vragenlijst-attest-1.pdf', $b);
        self::assertSame('janssens-marie-vragenlijst-attest-2.pdf', $c);
    }

    public function testResolveCollisionHandlesFilesWithNoExtension(): void
    {
        $used = ['janssens-marie-documenten-readme' => true];
        self::assertSame(
            'janssens-marie-documenten-readme-1',
            $this->exporter->resolveCollision($used, 'janssens-marie-documenten-readme'),
        );
    }

    public function testEnumerateYieldsFilesForOneRegistration(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[7] = $this->makeUser(['ID' => 7, 'first_name' => 'Marie', 'last_name' => 'Janssens']);
        $_test_attached_files[101] = '/tmp/stride-test-attest.pdf';
        file_put_contents('/tmp/stride-test-attest.pdf', 'pdf-bytes');

        $reg = [
            'id' => 1, 'user_id' => 7, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['files' => [101]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);

        self::assertCount(1, $rows);
        self::assertSame('/tmp/stride-test-attest.pdf', $rows[0]['path']);
        self::assertSame('janssens-marie-vragenlijst-stride-test-attest.pdf', $rows[0]['name']);

        unset($_test_users[7], $_test_attached_files[101]);
        @unlink('/tmp/stride-test-attest.pdf');
    }

    public function testEnumerateSkipsAnonymisedUser(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[8] = $this->makeUser(['ID' => 8, 'first_name' => 'X', 'last_name' => 'Y']);
        $_test_attached_files[201] = '/tmp/stride-test-x.pdf';
        file_put_contents('/tmp/stride-test-x.pdf', 'x');
        update_user_meta(8, '_stride_anonymised_at', time());

        $reg = [
            'id' => 2, 'user_id' => 8, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [201]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        delete_user_meta(8, '_stride_anonymised_at');
        unset($_test_users[8], $_test_attached_files[201]);
        @unlink('/tmp/stride-test-x.pdf');
    }

    public function testEnumerateSkipsMissingFileOnDisk(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[9] = $this->makeUser(['ID' => 9, 'first_name' => 'A', 'last_name' => 'B']);
        $_test_attached_files[301] = '/tmp/stride-does-not-exist.pdf';

        $reg = [
            'id' => 3, 'user_id' => 9, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [301]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        unset($_test_users[9], $_test_attached_files[301]);
    }

    public function testEnumerateSkipsOrphanRegistration(): void
    {
        global $_test_attached_files;
        $_test_attached_files[401] = '/tmp/stride-orphan.pdf';
        file_put_contents('/tmp/stride-orphan.pdf', 'x');

        $reg = [
            'id' => 4, 'user_id' => 99999, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [401]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);
        self::assertCount(0, $rows);

        unset($_test_attached_files[401]);
        @unlink('/tmp/stride-orphan.pdf');
    }

    public function testEnumerateAppendsCounterOnCollision(): void
    {
        global $_test_users, $_test_attached_files;
        $_test_users[10] = $this->makeUser(['ID' => 10, 'first_name' => 'Same', 'last_name' => 'Person']);
        // Two attachments pointing at the same basename — forces a collision.
        $_test_attached_files[501] = '/tmp/stride-a.pdf';
        $_test_attached_files[502] = '/tmp/stride-a.pdf';
        file_put_contents('/tmp/stride-a.pdf', 'a');

        $reg = [
            'id' => 5, 'user_id' => 10, 'edition_id' => 99,
            'completion_tasks' => wp_json_encode([
                'documents' => ['status' => 'completed', 'data' => ['files' => [501, 502]]],
            ]),
        ];

        $rows = iterator_to_array($this->makeExporter([$reg])->enumerate(99), false);

        self::assertCount(2, $rows);
        self::assertSame('person-same-documenten-stride-a.pdf', $rows[0]['name']);
        self::assertSame('person-same-documenten-stride-a-1.pdf', $rows[1]['name']);

        unset($_test_users[10], $_test_attached_files[501], $_test_attached_files[502]);
        @unlink('/tmp/stride-a.pdf');
    }

    private function makeUser(array $fields): WP_User
    {
        $user = new WP_User();
        $user->ID = $fields['ID'] ?? 1;
        $user->first_name = $fields['first_name'] ?? '';
        $user->last_name = $fields['last_name'] ?? '';
        return $user;
    }

    private function makeExporter(array $regs): EditionFilesZipExporter
    {
        $exporter = $this->getMockBuilder(EditionFilesZipExporter::class)
            ->setConstructorArgs([
                $this->createMock(EditionService::class),
                $this->createMock(EditionRepository::class),
                $this->createMock(RegistrationRepository::class),
            ])
            ->onlyMethods(['getRegistrations'])
            ->getMock();
        $exporter->method('getRegistrations')->willReturn($regs);
        return $exporter;
    }
}
