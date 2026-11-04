@format @format_spiffy @javascript
Feature: Spiffy spotlight mode
  In order to concentrate on what I can do now
  As a student
  I need the current card on centre stage, the other cards one click away, and the full list when I want it

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
      | activity | name           | intro              | course | section | completion |
      | wiki     | Read the guide | Start here         | SPIFFY | 1       | 1          |
      | wiki     | Say hello      | Then say hello too | SPIFFY | 1       | 1          |

  Scenario: A student focuses a card, sees it in the address, and switches to the full list and back
    When I am on the "SPIFFY" "Course" page logged in as "student1"
    Then ".format-spiffy.spiffy-view-spotlight" "css_element" should exist
    And I should see "Read the guide" in the "#section-1 .spiffy-wrap--stage > .spiffy-cards" "css_element"
    And I should see "Say hello" in the "#section-1 .spiffy-strip" "css_element"
    When I click on "Say hello" "link" in the "#section-1 .spiffy-strip" "css_element"
    Then I should see "Say hello" in the "#section-1 .spiffy-wrap--stage > .spiffy-cards" "css_element"
    And I should see "Read the guide" in the "#section-1 .spiffy-strip" "css_element"
    # The focused card is in the address (#card-<cmid>), so a reload keeps it on stage.
    When I reload the page
    Then I should see "Say hello" in the "#section-1 .spiffy-wrap--stage > .spiffy-cards" "css_element"
    And I should see "Read the guide" in the "#section-1 .spiffy-strip" "css_element"
    When I press "Show me everything"
    Then ".format-spiffy.spiffy-view-list" "css_element" should exist
    And ".spiffy-wrap--stage" "css_element" should not exist
    And I should see "Read the guide" in the "#section-1 .spiffy-cards" "css_element"
    And I should see "Say hello" in the "#section-1 .spiffy-cards" "css_element"
    When I reload the page
    Then ".format-spiffy.spiffy-view-list" "css_element" should exist
    When I press "Show me everything"
    Then ".format-spiffy.spiffy-view-spotlight" "css_element" should exist
    And I should see "Say hello" in the "#section-1 .spiffy-wrap--stage > .spiffy-cards" "css_element"
