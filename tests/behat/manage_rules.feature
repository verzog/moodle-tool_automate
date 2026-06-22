@tool @tool_automate
Feature: Manage automation rules
  In order to automate routine site administration
  As an administrator
  I need to create automation rules and see them listed

  Background:
    Given I log in as "admin"

  Scenario: The rules overview starts empty
    When I navigate to "Plugins > Admin tools > Automate" in site administration
    Then I should see "No rules yet"
    And I should see "New rule"

  Scenario: An administrator creates a rule and sees it listed
    When I navigate to "Plugins > Admin tools > Automate" in site administration
    And I press "New rule"
    And I set the field "Rule name" to "Smoke test rule"
    And I press "Save rule"
    And I navigate to "Plugins > Admin tools > Automate" in site administration
    Then I should see "Smoke test rule"
    And I should not see "No rules yet"
