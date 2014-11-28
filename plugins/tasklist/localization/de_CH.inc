<?php

/**
 * Localizations for the Kolab Tasks plugin
 *
 * Copyright (C) 2014, Kolab Systems AG
 *
 * For translation see https://www.transifex.com/projects/p/kolab/resource/tasklist/
 */

$labels = array();
$labels['navtitle'] = 'Aufgaben';
$labels['lists'] = 'Aufgabenlisten';
$labels['list'] = 'Liste';
$labels['tags'] = 'Tags';
$labels['tasklistsubscribe'] = 'List permanently';
$labels['listsearchresults'] = 'Available Tasklists';
$labels['findlists'] = 'Find tasklists...';
$labels['searchterms'] = 'Search terms';
$labels['notasklistsfound'] = 'No tasklists found';
$labels['nrtasklistsfound'] = '$nr tasklists found';
$labels['removelist'] = 'Remove from list';

$labels['newtask'] = 'Neue Aufgabe';
$labels['createtask'] = 'Create Task <Enter>';
$labels['createnewtask'] = 'Neue Aufgabe eingeben (z.B. Samstag, Rasenmähen)';
$labels['createfrommail'] = 'Als Aufgabe speichern';
$labels['mark'] = 'Markieren';
$labels['unmark'] = 'Markierung aufheben';
$labels['edit'] = 'Bearbeiten';
$labels['delete'] = 'Löschen';
$labels['title'] = 'Titel';
$labels['description'] = 'Beschreibung';
$labels['datetime'] = 'Due';
$labels['duetime'] = 'Due time';
$labels['start'] = 'Beginn';
$labels['starttime'] = 'Start time';
$labels['alarms'] = 'Erinnerung';
$labels['repeat'] = 'Wiederholung';
$labels['links'] = 'Reference';
$labels['status'] = 'Status';
$labels['status-needs-action'] = 'Needs action';
$labels['status-in-process'] = 'In process';
$labels['status-completed'] = 'Completed';
$labels['status-cancelled'] = 'Gekündigt';
$labels['assignedto'] = 'Assigned to';
$labels['created'] = 'Created';
$labels['changed'] = 'Last Modified';

$labels['all'] = 'Alle';
$labels['flagged'] = 'Markiert';
$labels['complete'] = 'Erledigt';
$labels['completeness'] = 'Progress';
$labels['overdue'] = 'Überfällig';
$labels['today'] = 'Heute';
$labels['tomorrow'] = 'Morgen';
$labels['next7days'] = 'Nächste 7 Tage';
$labels['later'] = 'Später';
$labels['assigned'] = 'Assigned';
$labels['assignedtitle'] = 'Tasks you assigned to others';
$labels['mytasks'] = 'My tasks';
$labels['mytaskstitle'] = 'Tasks assigned to you';
$labels['nodate'] = 'kein Datum';
$labels['removetag'] = 'Löschen';
$labels['removelink'] = 'Remove email reference';
$labels['auto'] = 'Auto';

$labels['taskdetails'] = 'Details';
$labels['newtask'] = 'Neue Aufgabe';
$labels['edittask'] = 'Aufgabe bearbeiten';
$labels['save'] = 'Speichern';
$labels['cancel'] = 'Abbrechen';
$labels['saveandnotify'] = 'Save and Notify';
$labels['addsubtask'] = 'Neue Teilaufgabe';
$labels['deletetask'] = 'Aufgabe löschen';
$labels['deletethisonly'] = 'Nur diese Aufgabe löschen';
$labels['deletewithchilds'] = 'Mit allen Teilaufhaben löschen';
$labels['taskactions'] = 'Task options...';

$labels['tabsummary'] = 'Übersicht';
$labels['tabrecurrence'] = 'Wiederholung';
$labels['tabassignments'] = 'Assignments';
$labels['tabattachments'] = 'Anhänge';
$labels['tabsharing'] = 'Freigabe';

$labels['editlist'] = 'Liste bearbeiten';
$labels['createlist'] = 'Neue Liste';
$labels['listactions'] = 'Listenoptionen...';
$labels['listname'] = 'Name';
$labels['showalarms'] = 'Show reminders';
$labels['import'] = 'Importieren';
$labels['viewactions'] = 'View actions';
$labels['focusview'] = 'View only this list';

// date words
$labels['on'] = 'am';
$labels['at'] = 'um';
$labels['this'] = 'diesen';
$labels['next'] = 'nächsten';

// messages
$labels['savingdata'] = 'Daten werden gespeichert...';
$labels['errorsaving'] = 'Fehler beim Speichern.';
$labels['notasksfound'] = 'Für die aktuellen Kriterien wurden keine Aufgaben gefunden.';
$labels['invalidstartduedates'] = 'Beginn der Aufgabe darf nicht grösser als das Enddatum sein.';
$labels['deletetasktconfirm'] = 'Möchten Sie diese Aufgabe wirklich löschen?';
$labels['deleteparenttasktconfirm'] = 'Möchten Sie diese Aufgabe inklusive aller Teilaufgaben wirklich löschen?';
$labels['deletelistconfirm'] = 'Möchten Sie diese Liste mit allen Aufgaben wirklich löschen?';
$labels['deletelistconfirmrecursive'] = 'Do you really want to delete this list with all its sub-lists and tasks?';
$labels['aclnorights'] = 'You do not have administrator rights on this task list.';
$labels['changetaskconfirm'] = 'Update task';
$labels['changeconfirmnotifications'] = 'Do you want to notify the attendees about the modification?';
$labels['partstatupdatenotification'] = 'Do you want to notify the organizer about the status change?';

// (hidden) titles and labels for accessibility annotations
$labels['quickaddinput'] = 'New task date and title';
$labels['arialabelquickaddbox'] = 'Quick add new task';
$labels['arialabelsearchform'] = 'Task search form';
$labels['arialabelquicksearchbox'] = 'Task search input';
$labels['arialabellistsearchform'] = 'Tasklists search form';
$labels['arialabeltaskselector'] = 'List mode';
$labels['arialabeltasklisting'] = 'Tasks listing';

// attendees
$labels['attendee'] = 'Assignee';
$labels['role'] = 'Rolle';
$labels['availability'] = 'Verfüg.';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Add assignee';
$labels['roleorganizer'] = 'Organisator';
$labels['rolerequired'] = 'Erforderlich';
$labels['roleoptional'] = 'Optional';
$labels['rolechair'] = 'Chair';
$labels['rolenonparticipant'] = 'Observer';
$labels['sendinvitations'] = 'Einladungen versenden';
$labels['sendnotifications'] = 'Notify assignees about modifications';
$labels['sendcancellation'] = 'Notify assignees about task cancellation';
$labels['invitationsubject'] = 'You\'ve been assigned to "$title"';
$labels['invitationmailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\nPlease find attached an iCalendar file with all the task details which you can import to your tasks application.";
$labels['itipupdatesubject'] = '"$title" wurde aktualisiert';
$labels['itipupdatesubjectempty'] = 'A task that concerns you has been updated';
$labels['itipupdatemailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\nPlease find attached an iCalendar file with the updated task details which you can import to your tasks application.";
$labels['itipcancelsubject'] = '"$title" wurde abgesagt';
$labels['itipcancelmailbody'] = "*\$title*\n\nDue: \$date\n\nAssignees: \$attendees\n\nThe task has been cancelled by \$organizer.\n\nPlease find attached an iCalendar file with the updated task details.";
$labels['saveintasklist'] = 'save in ';

// invitation handling (overrides labels from libcalendaring)
$labels['itipobjectnotfound'] = 'The task referred by this message was not found in your tasks list.';

$labels['itipmailbodyaccepted'] = "\$sender has accepted the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodytentative'] = "\$sender has tentatively accepted the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodydeclined'] = "\$sender has declined the assignment to the following task:\n\n*\$title*\n\nDue: \$date\n\nAssignees: \$attendees";
$labels['itipmailbodycancel'] = "\$sender has rejected your assignment to the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodyin-process'] = "\$sender has set the status of the following task to in-process:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodycompleted'] = "\$sender has completed the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodydelegated'] = "\$sender has delegated the following task:\n\n*\$title*\n\nDue: \$date";
$labels['itipmailbodydelegatedto'] = "\$sender has delegated the following task to you:\n\n*\$title*\n\nDue: \$date";

$labels['attendeeaccepted'] = 'Assignee has accepted';
$labels['attendeetentative'] = 'Assignee has tentatively accepted';
$labels['attendeedeclined'] = 'Assignee has declined';
$labels['attendeedelegated'] = 'Assignee has delegated to $delegatedto';
$labels['attendeein-process'] = 'Assignee is in-process';
$labels['attendeecompleted'] = 'Assignee has completed';

$labels['acceptinvitation'] = 'Do you accept this assignment?';
$labels['itipdeclinetask'] = 'Decline your assignment to this task to the organizer';
$labels['declinedeleteconfirm'] = 'Do you also want to delete this declined task from your tasks list?';
$labels['itipcomment'] = 'Invitation/notification comment';
$labels['itipcommenttitle'] = 'This comment will be attached to the invitation/notification message sent to assignees';
$labels['itipsendsuccess'] = 'Notification sent to assignees';
$labels['errornotifying'] = 'Failed to send notifications to task assignees';
$labels['removefromcalendar'] = 'Remove from my tasks';
$labels['delegateinvitation'] = 'Delegate assignment';

$labels['andnmore'] = '$nr weitere...';
$labels['delegatedto'] = 'Delegated to: ';
$labels['delegatedfrom'] = 'Delegated from: ';
$labels['savetotasklist'] = 'Save to tasks';
$labels['comment'] = 'Kommentar';
$labels['errorimportingtask'] = 'Failed to import task(s)';
$labels['importwarningexists'] = 'A copy of this task already exists in your tasklist.';
$labels['importsuccess'] = 'Successfully imported $nr tasks';
$labels['newerversionexists'] = 'A newer version of this task already exists! Aborted.';
$labels['nowritetasklistfound'] = 'No tasklist found to save the task';
$labels['importedsuccessfully'] = 'The task was successfully added to \'$list\'';
$labels['updatedsuccessfully'] = 'The task was successfully updated in \'$list\'';
$labels['attendeupdateesuccess'] = 'Teilnehmerstatus erfolgreich aktualisiert';
$labels['itipresponseerror'] = 'Failed to send the response to this task assignment';
$labels['itipinvalidrequest'] = 'Diese Einladung ist nicht mehr gültig';
$labels['sentresponseto'] = 'Successfully sent assignment response to $mailto';
$labels['successremoval'] = 'The task has been deleted successfully.';

$labels['arialabelsortmenu'] = 'Tasks sorting options';
