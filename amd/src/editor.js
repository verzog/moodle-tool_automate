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

/**
 * Inline editor behaviour for the rule edit page.
 *
 * Three jobs, all wired through event delegation on document so the
 * bindings survive the AJAX fragment swaps below and don't depend on the
 * pickers/links existing when init() first runs:
 *  - show/hide the Step 5 trigger sub-fields and the Match/expression row
 *    based on the current selections (re-implemented here rather than via
 *    the moodleform hideIf, whose bindings are lost after a swap);
 *  - intercept the inline picker submits and edit links, fetch the
 *    section's HTML via inline=1, and swap it into the page; and
 *  - POST the inline condition/action forms via fetch and swap every
 *    section from the response, while letting the off-page trigger save
 *    submit normally.
 *
 * @module     tool_automate/editor
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    trigger: '[data-inline-target="trigger"]',
    conditions: '[data-inline-target="conditions"]',
    inlineTarget: '[data-inline-target]',
    picker: 'form.tool_automate-picker',
    editLink: 'a.tool_automate-inline-edit',
};

/**
 * Show or hide each Step 5 sub-field based on the trigger type and
 * schedule currently chosen.
 *
 * @method applyTrigger
 */
const applyTrigger = () => {
    const container = document.querySelector(SELECTORS.trigger);
    if (!container) {
        return;
    }
    const valueOf = (id) => {
        const el = container.querySelector('#' + id);
        return el ? el.value : '';
    };
    const triggertype = valueOf('id_triggertype');
    const schedule = valueOf('id_schedule');
    const eventname = valueOf('id_eventname');
    const showRow = (name, on) => {
        // Moodleform wraps each field's row in an element whose id is
        // "fitem_id_<name>" - target the row directly so this works for
        // compound widgets too (date_time_selector etc.) where the field
        // has no single matching .fitem child.
        const row = container.querySelector('#fitem_id_' + name);
        if (row) {
            row.style.display = on ? '' : 'none';
        }
    };
    showRow('schedule', triggertype === 'cron');
    showRow('scheduledate', triggertype === 'cron' && schedule === 'oncedate');
    showRow('eventname', triggertype === 'event');
    showRow('courseid', triggertype === 'event' && eventname === '\\core\\event\\course_completed');
    showRow('roleid', triggertype === 'event' && eventname === '\\core\\event\\role_assigned');
};

/**
 * Show or hide the custom-expression row based on the Match picker, which
 * Moodle's own hideIf stops driving after an AJAX swap.
 *
 * @method applyLogic
 */
const applyLogic = () => {
    const container = document.querySelector(SELECTORS.conditions);
    if (!container) {
        return;
    }
    const select = container.querySelector('#id_logic');
    const row = container.querySelector('#fitem_id_expression');
    if (!select || !row) {
        return;
    }
    row.style.display = select.value === 'expression' ? '' : 'none';
};

/**
 * Inject the scripts found in a fetched fragment into the live document so
 * any per-section init code runs after the swap.
 *
 * @method runScripts
 * @param {HTMLScriptElement[]} scripts
 */
const runScripts = (scripts) => {
    scripts.forEach((node) => {
        const fresh = document.createElement('script');
        if (node.src) {
            fresh.src = node.src;
        } else {
            fresh.textContent = node.textContent;
        }
        document.head.appendChild(fresh);
    });
};

/**
 * GET a single section's HTML via inline=1 and swap it into the live DOM.
 *
 * @method fetchAndReplace
 * @param {URL} url
 * @param {Element} target The data-inline-target section being replaced.
 */
const fetchAndReplace = (url, target) => {
    url.searchParams.set('inline', '1');
    return fetch(url.toString(), {credentials: 'same-origin'})
        .then((response) => response.text())
        .then((html) => {
            // Parse the fragment with DOMParser rather than assigning to
            // innerHTML - the parser interprets HTML semantically and
            // doesn't execute inline scripts on assignment.
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const replacement = doc.querySelector(
                '[data-inline-target="' + target.dataset.inlineTarget + '"]'
            );
            if (replacement) {
                const scripts = Array.prototype.slice.call(doc.querySelectorAll('script'));
                target.replaceWith(replacement);
                runScripts(scripts);
                applyTrigger();
                applyLogic();
            }
            return html;
        })
        .catch(() => {
            // Fall back to a full-page navigation, but to the human-facing
            // URL, not the inline=1 fragment endpoint.
            url.searchParams.delete('inline');
            window.location.href = url.toString();
        });
};

/**
 * POST an inline form via fetch and swap every section from the response,
 * or follow an off-page redirect with a real navigation.
 *
 * @method submitAndSwap
 * @param {HTMLFormElement} form
 * @param {HTMLElement} [submitter] The button that triggered the submit.
 */
const submitAndSwap = (form, submitter) => {
    const url = new URL(form.action, window.location.href);
    // Pass the submitter so the clicked button's name/value is included;
    // without it, PHP's $mform->is_cancelled() never sees the cancel
    // button and the save path runs instead.
    const data = submitter ? new FormData(form, submitter) : new FormData(form);
    return fetch(url.toString(), {method: 'POST', body: data, credentials: 'same-origin'})
        .then((response) => {
            // If PHP redirected us off the edit page, follow it with a real
            // navigation - the fragment-swap below would silently no-op
            // against a page that has no matching sections.
            const finalUrl = new URL(response.url, window.location.href);
            if (finalUrl.pathname !== window.location.pathname) {
                window.location.href = finalUrl.toString();
                return null;
            }
            return response.text();
        })
        .then((html) => {
            if (html !== null) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const scripts = [];
                doc.querySelectorAll(SELECTORS.inlineTarget).forEach((replacement) => {
                    const name = replacement.dataset.inlineTarget;
                    const live = document.querySelector('[data-inline-target="' + name + '"]');
                    if (live) {
                        Array.prototype.slice.call(replacement.querySelectorAll('script'))
                            .forEach((script) => scripts.push(script));
                        live.replaceWith(replacement);
                    }
                });
                runScripts(scripts);
                applyTrigger();
                applyLogic();
            }
            return html;
        })
        .catch(() => form.submit());
};

/**
 * Wire up the inline editor on the rule edit page.
 *
 * @method init
 */
export const init = () => {
    document.addEventListener('change', (e) => {
        if (!e.target.closest) {
            return;
        }
        if (e.target.closest(SELECTORS.trigger)) {
            applyTrigger();
        }
        if (e.target.closest(SELECTORS.conditions)) {
            applyLogic();
        }
    });

    document.addEventListener('submit', (e) => {
        const form = e.target.closest(SELECTORS.picker);
        if (!form) {
            return;
        }
        const select = form.querySelector('select');
        if (!select || !select.value) {
            return;
        }
        const target = form.closest(SELECTORS.inlineTarget);
        if (!target) {
            return;
        }
        e.preventDefault();
        const url = new URL(form.action, window.location.href);
        new FormData(form).forEach((value, key) => url.searchParams.set(key, value));
        fetchAndReplace(url, target);
    }, true);

    document.addEventListener('click', (e) => {
        const link = e.target.closest(SELECTORS.editLink);
        if (!link) {
            return;
        }
        const target = link.closest(SELECTORS.inlineTarget);
        if (!target) {
            return;
        }
        e.preventDefault();
        fetchAndReplace(new URL(link.href, window.location.href), target);
    }, true);

    // Any other form submit inside a section gets POSTed via fetch and the
    // response's sections swapped in.
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form');
        if (!form || form.classList.contains('tool_automate-picker')) {
            return;
        }
        const target = form.closest(SELECTORS.inlineTarget);
        if (!target) {
            return;
        }
        // The trigger form's Save is the last editing step and redirects
        // off this page back to the rules overview. Let it submit as a
        // normal full-page POST so the browser follows PHP's redirect
        // natively; intercepting it with fetch cannot reliably follow an
        // off-page redirect when Moodle renders an HTML redirect page
        // instead of a bare 302.
        if (form.querySelector('[name="updatetrigger"]')) {
            return;
        }
        e.preventDefault();
        submitAndSwap(form, e.submitter);
    }, true);

    applyTrigger();
    applyLogic();
};
