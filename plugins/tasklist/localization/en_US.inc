<?php

$labels = array();
$labels['navtitle'] = 'Tasks';
$labels['lists'] = 'Tasklists';
$labels['list'] = 'Tasklist';
$labels['tags'] = 'Tags';
$labels['tasklistsubscribe'] = 'List permanently';
$labels['listsearchresults'] = 'Available Tasklists';
$labels['findlists'] = 'Find tasklists...';
$labels['searchterms'] = 'Search terms';
$labels['notasklistsfound'] = 'No tasklists found';
$labels['nrtasklistsfound'] = '$nr tasklists found';

$labels['newtask'] = 'New Task';
$labels['createtask'] = 'Create Task <Enter>';
$labels['createnewtask'] = 'Create new Task (e.g. Saturday, Mow the lawn)';
$labels['createfrommail'] = 'Save as task';
$labels['mark'] = 'Mark';
$labels['unmark'] = 'Unmark';
$labels['edit'] = 'Edit';
$labels['delete'] = 'Delete';
$labels['title'] = 'Title';
$labels['description'] = 'Description';
$labels['datetime'] = 'Due';
$labels['duetime'] = 'Due time';
$labels['start'] = 'Start';
$labels['starttime'] = 'Start time';
$labels['alarms'] = 'Reminder';
$labels['repeat'] = 'Repeat';
$labels['status'] = 'Status';
$labels['status-needs-action'] = 'Needs action';
$labels['status-in-process'] = 'In process';
$labels['status-completed'] = 'Completed';
$labels['status-cancelled'] = 'Cancelled';

$labels['all'] = 'All';
$labels['flagged'] = 'Flagged';
$labels['complete'] = 'Complete';
$labels['overdue'] = 'Overdue';
$labels['today'] = 'Today';
$labels['tomorrow'] = 'Tomorrow';
$labels['next7days'] = 'Next 7 days';
$labels['later'] = 'Later';
$labels['nodate'] = 'no date';
$labels['removetag'] = 'Remove';

$labels['taskdetails'] = 'Details';
$labels['newtask'] = 'New Task';
$labels['edittask'] = 'Edit Task';
$labels['save'] = 'Save';
$labels['cancel'] = 'Cancel';
$labels['addsubtask'] = 'Add subtask';
$labels['deletetask'] = 'Delete task';
$labels['deletethisonly'] = 'Delete this task only';
$labels['deletewithchilds'] = 'Delete with all subtasks';
$labels['taskactions'] = 'Task options...';

$labels['tabsummary'] = 'Summary';
$labels['tabrecurrence'] = 'Recurrence';
$labels['tabassignments'] = 'Assignments';
$labels['tabattachments'] = 'Attachments';
$labels['tabsharing'] = 'Sharing';

$labels['editlist'] = 'Edit list';
$labels['createlist'] = 'Add list';
$labels['listactions'] = 'List options...';
$labels['listname'] = 'Name';
$labels['showalarms'] = 'Show reminders';
$labels['import'] = 'Import';
$labels['viewoptions'] = 'View options';
$labels['focusview'] = 'View only this list';

// date words
$labels['on'] = 'on';
$labels['at'] = 'at';
$labels['this'] = 'this';
$labels['next'] = 'next';

// mesages
$labels['savingdata'] = 'Saving data...';
$labels['errorsaving'] = 'Failed to save data.';
$labels['notasksfound'] = 'No tasks found for the given criteria';
$labels['invalidstartduedates'] = 'Start date must not be greater than due date.';
$labels['deletetasktconfirm'] = 'Do you really want to delete this task?';
$labels['deleteparenttasktconfirm'] = 'Do you really want to delete this task and all its subtasks?';
$labels['deletelistconfirm'] = 'Do you really want to delete this list with all its tasks?';
$labels['deletelistconfirmrecursive'] = 'Do you really want to delete this list with all its sub-lists and tasks?';
$labels['aclnorights'] = 'You do not have administrator rights on this task list.';

// (hidden) titles and labels for accessibility annotations
$labels['quickaddinput'] = 'New task date and title';
$labels['arialabelquickaddbox'] = 'Quick add new task';
$labels['arialabelsearchform'] = 'Task search form';
$labels['arialabelquicksearchbox'] = 'Task search input';
$labels['arialabellistsearchform'] = 'Tasklists search form';
$labels['arialabeltaskselector'] = 'List mode';
$labels['arialabeltasklisting'] = 'Tasks listing';

// attendees
$labels['attendee'] = 'Participant';
$labels['role'] = 'Role';
$labels['availability'] = 'Avail.';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Add participant';
$labels['roleorganizer'] = 'Organizer';
$labels['rolerequired'] = 'Required';
$labels['roleoptional'] = 'Optional';
$labels['rolechair'] = 'Chair';
$labels['rolenonparticipant'] = 'Absent';
$labels['sendinvitations'] = 'Send invitations';
$labels['sendnotifications'] = 'Notify participants about modifications';
$labels['sendcancellation'] = 'Notify participants about task cancellation';
$labels['invitationsubject'] = 'You\'ve been invited to "$title"';
$labels['invitationmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with all the task details which you can import to your tasks application.";
$labels['invitationattendlinks'] = "In case your email client doesn't support iTip requests you can use the following link to either accept or decline this invitation:\n\$url";
$labels['eventupdatesubject'] = '"$title" has been updated';
$labels['eventupdatesubjectempty'] = 'A task that concerns you has been updated';
$labels['eventupdatemailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with the updated task details which you can import to your tasks application.";
$labels['eventcancelsubject'] = '"$title" has been canceled';
$labels['eventcancelmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nThe task has been cancelled by \$organizer.\n\nPlease find attached an iCalendar file with the updated task details.";

// invitation handling (overrides labels from libcalendaring)
$labels['itipobjectnotfound'] = 'The task referred by this message was not found in your tasks list.';

$labels['itipmailbodyaccepted'] = "\$sender has accepted the assignment to the following task:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipmailbodytentative'] = "\$sender has tentatively accepted the assignment to the following task:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipmailbodydeclined'] = "\$sender has declined the assignment to the following task:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipmailbodycancel'] = "\$sender has rejected your assignment to the following task:\n\n*\$title*\n\nWhen: \$date";

$labels['itipdeclineevent'] = 'Do you want to decline your assignment to this task?';
$labels['declinedeleteconfirm'] = 'Do you also want to delete this declined task from your tasks list?';
$labels['itipcomment'] = 'Invitation/notification comment';
$labels['itipcommenttitle'] = 'This comment will be attached to the invitation/notification message sent to participants';
$labels['itipsendsuccess'] = 'Invitation sent to participants.';
$labels['errornotifying'] = 'Failed to send notifications to task participants';

$labels['andnmore'] = '$nr more...';
$labels['delegatedto'] = 'Delegated to: ';
$labels['delegatedfrom'] = 'Delegated from: ';
