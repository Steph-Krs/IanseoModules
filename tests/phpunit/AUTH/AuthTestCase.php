<?php
/**
 * Base of the AUTH tests: its libraries loaded once, in the order the check-in desk and
 * licensee space pages load them.
 */
abstract class AuthTestCase extends IanseoTestCase
{
    public const LIBS = array(
        'lib.php',
        'licence-lib.php',
        'booking/lib/ui.php',
        'booking/lib/registration.php',
        'booking/lib/targets.php',
        'booking/lib/compstats.php',
        'shop/lib/staff-session.php',
        'shop/lib/pay.php',
        'shop/lib/desk.php',
        'shop/lib/desk-cash.php',
    );

    public static function setUpBeforeClass(): void
    {
        self::loadModule('AUTH', self::LIBS);
    }
}
