@format @format_spiffy @javascript
Feature: Student tools on Spiffy cards
  In order to keep track of my questions and my learning
  As a student
  I need to keep private notes on a card, ask my teacher about it and say how confident I feel

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname     | shortname | format | numsections | enablecompletion | summary |
      | Spiffy trial | SPIFFY    | spiffy | 1           | 1                |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | SPIFFY | editingteacher |
      | student1 | SPIFFY | student        |
    And the following "activities" exist:
      | activity | name       | intro          | course | section | completion |
      | forum    | Talk forum | Say hello here | SPIFFY | 1       | 1          |

  Scenario: A student saves a private note on a card
    Given I am on the "SPIFFY" "Course" page logged in as "student1"
    When I click on "summary.spiffy-tool-toggle--note" "css_element"
    Then I should see "Only you can see this"
    And I set the field "My notes on Talk forum" to "Ask about the second post"
    And I press "Save to my notes"
    And I should see "Saved. Only you can see this."
    And I reload the page
    And the field "My notes on Talk forum" matches value "Ask about the second post"

  Scenario: A student confirms a question before it is sent to the teacher
    Given I am on the "SPIFFY" "Course" page logged in as "student1"
    When I click on "summary.spiffy-tool-toggle--ask" "css_element"
    Then I should see "To: Terry Teacher"
    And I set the field "Your question about Talk forum" to "When is this due?"
    And I click on "Ask my teacher" "button" in the ".spiffy-ask" "css_element"
    And I should see "Are you sure you're ready to send?"
    And I press "Keep editing"
    And I should not see "Are you sure you're ready to send?"
    And I click on "Ask my teacher" "button" in the ".spiffy-ask" "css_element"
    And I press "Send"
    And I should see "Sent to Terry Teacher"
    And I am on the "SPIFFY" "Course" page logged in as "teacher1"
    And I open messaging
    And I select "Sam Student" conversation in messaging
    And I should see "When is this due?"

  Scenario: A student says how confident they feel once the card is done
    Given I am on the "SPIFFY" "Course" page logged in as "student1"
    And I should not see "How confident do you feel?"
    When I press "Mark as done"
    And I reload the page
    # A done card waits as a small tile; focusing it shows its tools.
    And I click on "Talk forum" "link" in the ".spiffy-card" "css_element"
    Then I should see "How confident do you feel?"
    And I click on "Confident" "button" in the ".spiffy-confidence" "css_element"
    And I should see "Thanks. You said: Confident."
    And the "aria-pressed" attribute of "Confident" "button" should contain "true"

  Scenario: A teacher does not get the student tools
    When I am on the "SPIFFY" "Course" page logged in as "teacher1"
    Then "Save to my notes" "button" should not exist
    And ".spiffy-tools" "css_element" should not exist
