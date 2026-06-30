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

namespace tool_automate\condition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit test for condition_base::wildcard_match().
 *
 * The matcher backs the email/username/name/course-name/course-idnumber
 * "matches" conditions. It must behave like the previous regex (case
 * insensitive; no `*` means a substring match; `*` matches any run) while
 * never backtracking catastrophically on a pathological pattern.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition_base::class)]
final class wildcard_match_test extends \basic_testcase {
    /**
     * Invoke the protected static matcher.
     *
     * @param string $pattern
     * @param string $haystack
     * @return bool
     */
    protected static function match(string $pattern, string $haystack): bool {
        $method = new \ReflectionMethod(condition_base::class, 'wildcard_match');
        $method->setAccessible(true);
        return (bool) $method->invoke(null, $pattern, $haystack);
    }

    /**
     * Cases covering substring, anchored wildcard, case-insensitivity, empty
     * input and a ReDoS-style pattern.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function cases(): array {
        return [
            'substring (no wildcard)'        => ['@example.com', 'alice@example.com', true],
            'substring no match'             => ['@example.com', 'bob@other.org', false],
            'leading wildcard'               => ['*@example.com', 'alice@example.com', true],
            'trailing wildcard'              => ['admin*', 'admin_smith', true],
            'wildcard both ends'             => ['*foo*bar*', 'xfooYbarZ', true],
            'wildcard wrong order'           => ['*foo*bar*', 'xbarYfooZ', false],
            'case insensitive'              => ['EXACT', 'exact', true],
            'inner wildcard match'           => ['a*z', 'abbbz', true],
            'inner wildcard no tail'         => ['a*z', 'abbb', false],
            'bare star matches anything'     => ['*', 'anything at all', true],
            'collapsed stars'                => ['***a***', 'xxxaxxx', true],
            'empty pattern never matches'    => ['', 'something', false],
            'empty haystack with star'       => ['*', '', true],
            'redos pattern returns false'    => ['*a*a*a*a*a*a*a*a*', str_repeat('b', 64), false],
        ];
    }

    /**
     * The matcher returns the expected result for each case.
     *
     * @param string $pattern
     * @param string $haystack
     * @param bool $expected
     */
    #[DataProvider('cases')]
    public function test_wildcard_match(string $pattern, string $haystack, bool $expected): void {
        $this->assertSame($expected, self::match($pattern, $haystack));
    }

    /**
     * A concrete condition that depends on the matcher behaves as expected,
     * confirming the helper is wired through the public matches() path.
     */
    public function test_used_by_concrete_condition(): void {
        $condition = new email_matches(['pattern' => '*@example.com']);
        $this->assertTrue($condition->matches((object) ['email' => 'alice@example.com']));
        $this->assertFalse($condition->matches((object) ['email' => 'bob@other.org']));
    }
}
