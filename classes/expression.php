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

/**
 * Tiny safe boolean expression evaluator.
 *
 * Grammar:
 *   expr   ::= term ( OR term )*
 *   term   ::= factor ( AND factor )*
 *   factor ::= NOT factor | '(' expr ')' | IDENT | 'true' | 'false'
 *   IDENT  ::= [A-Za-z_][A-Za-z0-9_]*
 *
 * Identifiers resolve to keys in the supplied $values array; missing keys
 * are treated as false.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class expression {
    /** @var array Token list. */
    private array $tokens;

    /** @var int Current token index. */
    private int $pos;

    /**
     * Tokenise the input.
     *
     * @param string $input
     * @return array
     * @throws \invalid_parameter_exception On invalid input.
     */
    private static function tokenise(string $input): array {
        $tokens = [];
        $len = strlen($input);
        $i = 0;
        while ($i < $len) {
            $ch = $input[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '(' || $ch === ')') {
                $tokens[] = ['type' => $ch, 'value' => $ch];
                $i++;
                continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $i = self::tokenise_word($input, $i, $len, $tokens);
                continue;
            }
            throw new \invalid_parameter_exception("Unexpected character '$ch' in expression");
        }
        return $tokens;
    }

    /**
     * Read an identifier (or reserved word) starting at $i and append a
     * token. Returns the new cursor position.
     *
     * @param string $input
     * @param int $i
     * @param int $len
     * @param array $tokens
     * @return int
     */
    private static function tokenise_word(string $input, int $i, int $len, array &$tokens): int {
        $j = $i + 1;
        while ($j < $len && (ctype_alnum($input[$j]) || $input[$j] === '_')) {
            $j++;
        }
        $word = substr($input, $i, $j - $i);
        $upper = strtoupper($word);
        if (in_array($upper, ['AND', 'OR', 'NOT', 'TRUE', 'FALSE'], true)) {
            $tokens[] = ['type' => $upper, 'value' => $upper];
        } else {
            $tokens[] = ['type' => 'IDENT', 'value' => $word];
        }
        return $j;
    }

    /**
     * Evaluate the expression against a map of identifier => bool.
     *
     * @param string $input
     * @param array $values Map of identifier => bool.
     * @return bool
     * @throws \invalid_parameter_exception On parse error.
     */
    public static function evaluate(string $input, array $values): bool {
        $parser = new self();
        $parser->tokens = self::tokenise($input);
        $parser->pos = 0;
        if (empty($parser->tokens)) {
            return false;
        }
        $result = $parser->parse_expr($values);
        if ($parser->pos !== count($parser->tokens)) {
            throw new \invalid_parameter_exception('Trailing tokens after expression');
        }
        return $result;
    }

    /**
     * Validate that an expression parses without errors.
     *
     * @param string $input
     * @return string|null Null on success, error message on failure.
     */
    public static function validate(string $input): ?string {
        try {
            self::evaluate($input, []);
        } catch (\invalid_parameter_exception $e) {
            return $e->getMessage();
        }
        return null;
    }

    /**
     * Parse expr = term (OR term)*.
     *
     * @param array $values Identifier map.
     * @return bool
     */
    private function parse_expr(array $values): bool {
        $result = $this->parse_term($values);
        while ($this->peek('OR')) {
            $this->consume();
            $right = $this->parse_term($values);
            $result = $result || $right;
        }
        return $result;
    }

    /**
     * Parse term = factor (AND factor)*.
     *
     * @param array $values Identifier map.
     * @return bool
     */
    private function parse_term(array $values): bool {
        $result = $this->parse_factor($values);
        while ($this->peek('AND')) {
            $this->consume();
            $right = $this->parse_factor($values);
            $result = $result && $right;
        }
        return $result;
    }

    /**
     * Parse factor = NOT factor | '(' expr ')' | IDENT | TRUE | FALSE.
     *
     * @param array $values Identifier map.
     * @return bool
     */
    private function parse_factor(array $values): bool {
        if ($this->peek('NOT')) {
            $this->consume();
            return !$this->parse_factor($values);
        }
        if ($this->peek('(')) {
            $this->consume();
            $result = $this->parse_expr($values);
            if (!$this->peek(')')) {
                throw new \invalid_parameter_exception("Missing closing parenthesis");
            }
            $this->consume();
            return $result;
        }
        if ($this->peek('TRUE')) {
            $this->consume();
            return true;
        }
        if ($this->peek('FALSE')) {
            $this->consume();
            return false;
        }
        if ($this->peek('IDENT')) {
            $token = $this->tokens[$this->pos];
            $this->consume();
            return !empty($values[$token['value']]);
        }
        throw new \invalid_parameter_exception("Unexpected token at position {$this->pos}");
    }

    /**
     * Is the current token of the given type?
     *
     * @param string $type
     * @return bool
     */
    private function peek(string $type): bool {
        return isset($this->tokens[$this->pos]) && $this->tokens[$this->pos]['type'] === $type;
    }

    /**
     * Advance past the current token.
     */
    private function consume(): void {
        $this->pos++;
    }
}
