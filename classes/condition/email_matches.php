<?php
namespace tool_automate\condition;

/**
 * Condition: the user's email matches a wildcard pattern (e.g. *@sccaorg.au).
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_matches extends condition_base {

    public static function get_name(): string {
        return get_string('cond_email_matches', 'tool_automate');
    }

    public function matches(\stdClass $user): bool {
        $pattern = trim($this->config['pattern'] ?? '');
        if ($pattern === '' || empty($user->email)) {
            return false;
        }
        // Turn the wildcard pattern into a safe, case-insensitive regex.
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $user->email);
    }
}
