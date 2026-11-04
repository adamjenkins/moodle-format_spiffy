@format @format_spiffy @javascript
Feature: The teacher side of Spiffy cards
  In order to look after my course without editing mode
  As a teacher
  I need to see what needs my attention, edit cards from a pencil menu, message students and preview as a student

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

  Scenario: Students do not see the teacher side
    When I am on the "SPIFFY" "Course" page logged in as "student1"
    Then I should not see "What needs my attention?"
    And "Edit Talk forum" "button" should not exist

  Scenario: A teacher edits a card from the pencil menu without editing mode
    Given I am on the "SPIFFY" "Course" page logged in as "teacher1"
    Then I should see "What needs my attention?"
    And I should see "1 not finished" in the ".spiffy-teacher-panel" "css_element"
    When I click on "Edit Talk forum" "button"
    Then "Edit settings" "link" should exist in the ".spiffy-pencil .dropdown-menu" "css_element"
    And "Duplicate" "link" should exist in the ".spiffy-pencil .dropdown-menu" "css_element"
    And "Delete" "link" should exist in the ".spiffy-pencil .dropdown-menu" "css_element"
    When I click on "Edit settings" "link" in the ".spiffy-pencil .dropdown-menu" "css_element"
    Then the field "Forum name" matches value "Talk forum"

  Scenario: A teacher messages the students who have not finished, after confirming
    Given I am on the "SPIFFY" "Course" page logged in as "teacher1"
    When I click on ".spiffy-teacher-message summary" "css_element"
    And I set the field "Message to the students who haven't finished (1)" to "Please say hello this week."
    And I press "Next"
    Then I should see "Students who will get this message: 1. Send it now?"
    When I press "Send"
    Then I should see "Messages sent: 1."

  Scenario: A teacher previews the course as a student and comes back
    Given I am on the "SPIFFY" "Course" page logged in as "teacher1"
    When I follow "Preview as student"
    Then I should see "You are previewing the course as a Student."
    And I should not see "What needs my attention?"
    And "Edit Talk forum" "button" should not exist
    When I click on "Return to my normal role" "link" in the ".spiffy-teacher-previewbar" "css_element"
    Then I should see "What needs my attention?"
