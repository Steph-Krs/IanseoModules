<?php
require_once __DIR__ . '/AuthTestCase.php';

/**
 * Ends keyed in arrow by arrow (Qualifications.QuD1-8Arrowstring): the core declares
 * QuD5-8Arrowstring with a collation of their own. A query comparing the CONCAT of the eight
 * columns stopped on error 1267 wherever the server's default is not utf8mb4_general_ci
 * (issue 13, AUTH 1.11.1): the licensee space and the competition statistics were down.
 */
final class ArrowsCollationTest extends AuthTestCase
{
    private const LIC = '9100002L';

    private function arrows(int $en, int $distance, string $arrows): void
    {
        TestDb::exec("UPDATE Qualifications SET QuD{$distance}Arrowstring = " . TestDb::quote($arrows)
            . " WHERE QuId = $en");
    }

    public function testRegistrationWithoutArrows(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));

        $mine = bk_my_registrations(self::LIC);
        $this->assertCount(1, $mine);
        $this->assertSame(0, (int) $mine[0]->BkHasArrows);
        $this->assertSame(array(), bk_cs_mine($tour, self::LIC));
    }

    public function testArrowsOnFirstDistance(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        $en = Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));
        $this->arrows($en, 1, 'JJI');

        $this->assertSame(1, (int) bk_my_registrations(self::LIC)[0]->BkHasArrows);
        $this->assertCount(1, bk_cs_mine($tour, self::LIC));
    }

    public function testArrowsOnLastDistance(): void
    {
        $tour = Fixtures::competition();
        Fixtures::booking($tour, 2);
        $en = Fixtures::archer($tour, 1, 0, '', array('licence' => self::LIC));
        $this->arrows($en, 8, 'JJI');

        $this->assertSame(1, (int) bk_my_registrations(self::LIC)[0]->BkHasArrows);
        $this->assertCount(1, bk_cs_mine($tour, self::LIC));
    }
}
