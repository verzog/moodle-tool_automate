<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_automate\action;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit test for action_base::csv_safe_row().
 *
 * Report cells that begin with a formula trigger (= + - @ tab CR) must be
 * neutralised with a leading apostrophe so an attacker-influenced value cannot
 * execute when the generated CSV is opened in a spreadsheet; safe values must
 * be left untouched.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(action_base::class)]
final class csv_safe_row_test extends \basic_testcase {
    /**
     * Invoke the protected static helper.
     *
     * @param array $row
     * @return array
     */
    protected static function sanitise(array $row): array {
        $method = new \ReflectionMethod(action_base::class, 'csv_safe_row');
        $method->setAccessible(true);
        return (array) $method->invoke(null, $row);
    }

    /**
     * Each formula-trigger character at the start of a cell is escaped.
     */
    public function test_formula_triggers_are_neutralised(): void {
        $row = self::sanitise([
            '=HYPERLINK("http://evil","x")',
            '+1+2',
            '-1+2',
            '@SUM(A1)',
            "\tlead tab",
            "\rlead cr",
        ]);
        $this->assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $row[0]);
        $this->assertSame("'+1+2", $row[1]);
        $this->assertSame("'-1+2", $row[2]);
        $this->assertSame("'@SUM(A1)", $row[3]);
        $this->assertSame("'\tlead tab", $row[4]);
        $this->assertSame("'\rlead cr", $row[5]);
    }

    /**
     * Ordinary values - including ones that merely contain (but do not start
     * with) a trigger character - are left exactly as they were.
     */
    public function test_safe_values_are_untouched(): void {
        $row = self::sanitise([
            'Alice',
            'alice@example.com',
            'a=b',
            '12345',
            '',
            'O\'Brien',
        ]);
        $this->assertSame('Alice', $row[0]);
        $this->assertSame('alice@example.com', $row[1]);
        $this->assertSame('a=b', $row[2]);
        $this->assertSame('12345', $row[3]);
        $this->assertSame('', $row[4]);
        $this->assertSame('O\'Brien', $row[5]);
    }

    /**
     * Safe numeric cell values are returned unchanged (and untyped-coerced),
     * so the pass never corrupts ordinary report numbers.
     */
    public function test_numeric_values_are_preserved(): void {
        $row = self::sanitise([0, 42, 3.5]);
        $this->assertSame(0, $row[0]);
        $this->assertSame(42, $row[1]);
        $this->assertSame(3.5, $row[2]);
    }

    /**
     * A leading minus is a formula trigger, so a negative value is escaped to
     * text - matching the OWASP CSV-injection guidance the helper follows.
     */
    public function test_leading_minus_is_escaped(): void {
        $row = self::sanitise(['-5', -5]);
        $this->assertSame("'-5", $row[0]);
        $this->assertSame("'-5", $row[1]);
    }
}
