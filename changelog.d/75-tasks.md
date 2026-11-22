<!-- highlight -->
**Tasks, beside your mail.** A checklist icon in the top bar opens your tasks next to the mail, with or
without the calendar. Add one by typing and pressing Enter, tick it off in the list, and open it for
notes, a due date, steps, a repeat, a reminder and files. **My Day** fills itself with what
is due, starts or reminds you today. Lists can also sync two ways with a CalDAV server such as
Nextcloud Tasks, keeping whatever other apps put on a task. See [Tasks](docs/features/tasks.md).
[#75](https://github.com/karatektus/pl_mail/issues/75).

**Migration:** adds the tables `task_list`, `task`, `task_alert_delivery` and `task_attachment`, all
empty. Files go in a new folder of the attachments store, on the volume it already has, so nothing
changes in the deployment.
