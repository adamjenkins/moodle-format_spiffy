@format @format_spiffy
Feature: Spiffy course format card layout
  In order to follow the course step by step
  As a student
  I need to see each activity as a card with its dates, completion and restrictions

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname     | shortname | format | numsections | enablecompletion | summary |
      | Spiffy trial | SPIFFY    | spiffy | 1           | 1                |         |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | SPIFFY | student |
    And the following "activities" exist:
      | activity | name                          | intro                     | course | section | completion | visible | availability                                                           |
      | forum    | Learn how to communicate here | Practise in the forum     | SPIFFY | 1       | 1          | 1       |                                                                        |
      | page     | Restricted page               | Not yet                   | SPIFFY | 1       | 0          | 1       | {"op":"&","c":[{"type":"date","d":"<","t":1393977600}],"showc":[true]} |
      | page     | Hidden page                   | Hidden                    | SPIFFY | 1       | 0          | 0       |                                                                        |
      | label    | Note                          | What happens next?        | SPIFFY | 1       | 0          | 1       |                                                                        |

  Scenario: A student sees activity cards, completion, restrictions and notes
    When I am on the "SPIFFY" "Course" page logged in as "student1"
    Then "Start Learn how to communicate here" "link" should exist in the "#section-1" "css_element"
    And "Mark as done" "button" should exist in the "#section-1" "css_element"
    And I should see "Restricted page" in the "#section-1" "css_element"
    And "Start Restricted page" "link" should not exist
    And I should see "Available until" in the "#section-1" "css_element"
    And I should not see "Hidden page"
    And I should see "What happens next?" in the ".spiffy-note-box" "css_element"
