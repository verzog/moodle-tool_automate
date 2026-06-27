@tool @tool_automate
Feature: Manage automation rules
  In order to automate routine site administration
  As an administrator
  I need to create automation rules and see them listed

  Background:
    Given I log in as "admin"

  Scenario: The rules overview starts empty
    When I navigate to "Plugins > Admin tools > Automate > Automation rules" in site administration
    Then I should see "No rules yet"
    And I should see "New rule"

  Scenario: An administrator creates a rule and sees it listed
    When I navigate to "Plugins > Admin tools > Automate > Automation rules" in site administration
    And I press "New rule"
    And I set the field "Rule name" to "Smoke test rule"
    And I press "Save rule"
    And I navigate to "Plugins > Admin tools > Automate > Automation rules" in site administration
    Then I should see "Smoke test rule"
    And I should not see "No rules yet"

  Scenario: Saving a trigger returns the admin to the rules overview
    When I navigate to "Plugins > Admin tools > Automate > Automation rules" in site administration
    And I press "New rule"
    And I set the field "Rule name" to "Trigger redirect rule"
    And I press "Save rule"
    And I set the field "When should this run?" to "Only when I run it manually"
    And I press "Save trigger"
    Then I should see "Trigger saved for rule"
    And I should see "Trigger redirect rule"
    And I should not see "Step 5: When should this run?"

  @javascript
  Scenario: Saving a schedule trigger returns the admin to the rules overview with JS on
    When I navigate to "Plugins > Admin tools > Automate > Automation rules" in site administration
    And I press "New rule"
    And I set the field "Rule name" to "Trigger redirect JS rule"
    And I press "Save rule"
    And I set the field "When should this run?" to "On a schedule"
    And I set the field "How often" to "Daily"
    And I press "Save trigger"
    Then I should see "Trigger saved for rule"
    And I should see "Trigger redirect JS rule"
    And I should not see "Step 5: When should this run?"
