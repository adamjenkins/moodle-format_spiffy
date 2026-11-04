# Spiffy course format (format_spiffy)

A course format for Moodle 5.3 that turns each section into a page of colourful activity cards. Students see the step they should do now, and can usually do it on the card. Teachers see what needs their attention on the course page.

## For students

- **Spotlight view:** the current section is open with its current step large on the stage, the other steps beside it, and the other sections shrunk to a bar with a progress ring. **Show me everything** switches to the full list, and the choice is remembered.
- **What's next:** open work at the top of the page, due work first. When nothing is due it suggests open activities, those not opened yet first.
- **Clear order:** activities linked by completion restrictions are numbered "Step 1", "Step 2" and so on. Pointing at a locked step draws arrows back to the step to do first.
- **Open alongside:** puts a second card next to the first. The address changes, so a card can be linked to.
- **Tools on every card:** private notes, Ask my teacher (a Moodle message, sent after a confirmation), and a confidence check on finished cards.
- **Confetti** when a section is finished, skipped for anyone whose device asks for reduced motion.

## Activities on the card

Page, URL (YouTube links play on the card), File (PDF preview), Book (when set per activity), Choice, Forum (with one discussion), Quiz (with one question), Lesson, Feedback, Assignment (online text), Glossary and Database. Other activities get a button that opens their own page. Each activity can be set to work on the card or to open its page.

## For teachers

- **What needs my attention?** New questions, work to grade and students stuck for 7 days or more.
- **A "For teachers" panel on every card:** counts, new questions, confidence results, Quick grade, Extend the deadline and Message those who haven't finished.
- **A pencil on every card** for the edit actions, with edit mode off, and **Preview as student**.
- **Force sequential** in a section's menu: each activity waits for the one before. It asks first, and **Remove sequential** undoes it. An activity with no earlier activity that tracks completion is left open, so nobody is locked out.
- In edit mode the page uses Moodle's standard layout, so every editing feature works as usual.

## Settings

**Course settings:** columns (1 to 4), card design, page width, hint arrows, course index, blocks drawer, General section always open, section label, and ten on/off switches: Spotlight view, Activities on the card, "Mark as done" on cards, My notes, Ask my teacher, Confidence check, Celebrate finished sections, What's next, Unlock hints and Teacher tools.

**Per activity,** in the activity's settings under "Spiffy course format": button text, card width, accent colour, and on the card or on its own page.

**Site defaults:** *Site administration > Plugins > Course formats > Spiffy* sets the default for every course setting.

## Requirements

Moodle 5.3. No other plugins, external services or accounts are needed. YouTube videos load from YouTube only when a course links to them.

## Installation

Copy this directory to `course/format/spiffy` under your Moodle `public/` directory and visit *Site administration > Notifications*. Then choose *Spiffy* as the course format in a course's settings.

## Course reset

A course reset that clears completion or unenrols users also clears students' notes, confidence answers and the question log. Other resets leave them.

## Licence

GNU GPL v3 or later. Parts of `lib.php` and the renderer follow Moodle's Custom sections (`format_topics`) format, and the activity cards follow each activity's own code; authors are credited in the file headers. Confetti uses canvas-confetti by Kiril Vatev (ISC, see `thirdpartylibs.xml`).
