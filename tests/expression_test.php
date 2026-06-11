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

namespace tool_automate;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the boolean expression evaluator.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(expression::class)]
final class expression_test extends \basic_testcase {
    /**
     * Each identifier resolves to a stored bool.
     */
    public function test_simple_identifier(): void {
        $this->assertTrue(expression::evaluate('c1', ['c1' => true]));
        $this->assertFalse(expression::evaluate('c1', ['c1' => false]));
        $this->assertFalse(expression::evaluate('cmissing', []));
    }

    /**
     * AND/OR/NOT precedence: AND binds tighter than OR.
     */
    public function test_and_or_not_precedence(): void {
        // c1 OR c2 AND c3  =>  c1 OR (c2 AND c3).
        $this->assertTrue(expression::evaluate('c1 OR c2 AND c3',
            ['c1' => true, 'c2' => false, 'c3' => true]));
        $this->assertFalse(expression::evaluate('c1 OR c2 AND c3',
            ['c1' => false, 'c2' => true, 'c3' => false]));
        $this->assertTrue(expression::evaluate('NOT c1', ['c1' => false]));
        $this->assertFalse(expression::evaluate('NOT c1', ['c1' => true]));
    }

    /**
     * Parentheses override default precedence.
     */
    public function test_parentheses(): void {
        $this->assertTrue(expression::evaluate('(c1 OR c2) AND c3',
            ['c1' => false, 'c2' => true, 'c3' => true]));
        $this->assertFalse(expression::evaluate('(c1 OR c2) AND c3',
            ['c1' => false, 'c2' => true, 'c3' => false]));
    }

    /**
     * Invalid input returns an error from validate().
     */
    public function test_validate_reports_errors(): void {
        $this->assertNull(expression::validate('c1 AND c2'));
        $this->assertNotNull(expression::validate('c1 AND'));
        $this->assertNotNull(expression::validate('c1 AND (c2'));
        $this->assertNotNull(expression::validate('c1 @ c2'));
    }
}
