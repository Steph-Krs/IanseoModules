# Changelog — AUTO_SEND

## 0.1.1 — 2026-11-16

Contact address of the module updated (credits page).

## 0.1.0 — 2026-09-30

First version.

Opens and closes the ISK-NG scoring and uploads the results to ianseo.net on a schedule, with no browser left open: a start and an end (date and time, clock changes handled through a named time zone), an upload interval of 5 minutes or more, the scoring sessions to open and close, and the same result lists as on the core's upload page. Items that ianseo does not offer yet (brackets before the shoot-offs, medals) are sent from the moment it does.

The work is done by a task the server runs every minute (cron on Linux, Task Scheduler on Windows); the page shows the exact line to install and says when the task is not running. Each upload runs the core's own upload code in a separate process, with a time limit; a failure is retried after 1, 2, 4… minutes, and the next success publishes everything, since each upload carries the complete results. At the end, the scoring is closed before a last upload.

A simulation mode builds everything as for a real upload and sends nothing to ianseo.net. An optional monitoring address (healthchecks.io or compatible) is called after each upload, so that an outside service raises the alarm when the uploads stop — including when the server or its network is down.
