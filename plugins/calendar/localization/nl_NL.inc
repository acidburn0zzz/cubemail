<?php

$labels = array();

// preferences
$labels['default_view'] = 'Standaard agenda';
$labels['time_format'] = 'Tijdsformaat';
$labels['timeslots'] = 'Time slots per hour';
$labels['first_day'] = 'Eerste weekdag';
$labels['first_hour'] = 'Eerste uur om weer te geven';
$labels['workinghours'] = 'Werkuren';
$labels['add_category'] = 'Categorie toevoegen';
$labels['remove_category'] = 'Categorie verwijderen';
$labels['defaultcalendar'] = 'Maak nieuwe afspraken in';
$labels['eventcoloring'] = 'Kleuren van afspraken';
$labels['coloringmode0'] = 'Volgens kalender';
$labels['coloringmode1'] = 'Volgens categorie';
$labels['coloringmode2'] = 'Kalender voor omtrek, categorie voor inhoud';
$labels['coloringmode3'] = 'Categorie voor omtrek, kalender voor inhoud';

// calendar
$labels['calendar'] = 'Agenda';
$labels['calendars'] = 'Kalenders';
$labels['category'] = 'Categorie';
$labels['categories'] = 'Categorieen';
$labels['createcalendar'] = 'Creeer nieuwe kalender';
$labels['editcalendar'] = 'Wijzig calendar eigenschappen';
$labels['name'] = 'Naam';
$labels['color'] = 'Kleur';
$labels['day'] = 'Dag';
$labels['week'] = 'Week';
$labels['month'] = 'Maand';
$labels['agenda'] = 'Agenda';
$labels['new'] = 'Nieuw';
$labels['new_event'] = 'Nieuwe afspraak';
$labels['edit_event'] = 'Wijzig evenement';
$labels['edit'] = 'Wijzig';
$labels['save'] = 'Bewaar';
$labels['remove'] = 'Verwijder';
$labels['cancel'] = 'Terug';
$labels['select'] = 'Selecteer';
$labels['print'] = 'Print';
$labels['printtitle'] = 'Print kalenders';
$labels['title'] = 'Samenvatting';
$labels['description'] = 'Beschrijving';
$labels['all-day'] = 'hele dag';
$labels['export'] = 'Exporteer naar ICS';
$labels['exporttitle'] = 'Als iCalender exporteren';
$labels['exportrange'] = 'Events from';
$labels['exportattachments'] = 'With attachments';
$labels['customdate'] = 'Custom date';
$labels['location'] = 'Locatie';
$labels['url'] = 'URL';
$labels['date'] = 'Datum';
$labels['start'] = 'Begin';
$labels['end'] = 'Eind';
$labels['selectdate'] = 'Kies datum';
$labels['freebusy'] = 'Toon mij als';
$labels['free'] = 'Vrij';
$labels['busy'] = 'Bezig';
$labels['outofoffice'] = 'Niet Aanwezig';
$labels['tentative'] = 'Misschien';
$labels['priority'] = 'Prioriteit';
$labels['sensitivity'] = 'Privacy';
$labels['public'] = 'publiek';
$labels['private'] = 'prive';
$labels['confidential'] = 'vertrouwelijk';
$labels['alarms'] = 'Herinnering';
$labels['generated'] = 'gegenereerd op';
$labels['printdescriptions'] = 'Print beschrijvingen';
$labels['parentcalendar'] = 'Insert inside';
$labels['searchearlierdates'] = '« Zoek voor eerdere evenementen';
$labels['searchlaterdates'] = 'Zoek voor latere evenementen »';
$labels['andnmore'] = '$nr meer...';
$labels['togglerole'] = 'Click to toggle role';
$labels['createfrommail'] = 'Bewaar als evenement';
$labels['importevents'] = 'Evenementen importeren';
$labels['importrange'] = 'Events from';
$labels['onemonthback'] = '1 maand terug';
$labels['nmonthsback'] = '$nr maanden terug';
$labels['showurl'] = 'Toon kalender URL';
$labels['showurldescription'] = 'Use the following address to access (read only) your calendar from other applications. You can copy and paste this into any calendar software that supports the iCal format.';
$labels['caldavurldescription'] = 'Copy this address to a <a href="http://en.wikipedia.org/wiki/CalDAV" target="_blank">CalDAV</a> client application (e.g. Evolution or Mozilla Thunderbird) to fully synchronize this specific calendar with your computer or mobile device.';

// agenda view
$labels['listrange'] = 'Range to display:';
$labels['listsections'] = 'Verdeel in:';
$labels['smartsections'] = 'Smart sections';
$labels['until'] = 'tot';
$labels['today'] = 'Vandaag';
$labels['tomorrow'] = 'Morgen';
$labels['thisweek'] = 'Deze week';
$labels['nextweek'] = 'Volgende week';
$labels['thismonth'] = 'Deze maand';
$labels['nextmonth'] = 'Volgende maand';
$labels['weekofyear'] = 'Week';
$labels['pastevents'] = 'Verleden';
$labels['futureevents'] = 'Toekomst';

// alarm/reminder settings
$labels['showalarms'] = 'Show reminders';
$labels['defaultalarmtype'] = 'Standaard herinnering instelling';
$labels['defaultalarmoffset'] = 'Standaard herinnering tijd';

// attendees
$labels['attendee'] = 'Deelnemer';
$labels['role'] = 'Rol';
$labels['availability'] = 'Beschikb.';
$labels['confirmstate'] = 'Status';
$labels['addattendee'] = 'Deelnemer toevoegen';
$labels['roleorganizer'] = 'Organisatie';
$labels['rolerequired'] = 'Verplicht';
$labels['roleoptional'] = 'Optioneel';
$labels['rolechair'] = 'Chair';
$labels['rolenonparticipant'] = 'Absent';
$labels['cutypeindividual'] = 'Individual';
$labels['cutypegroup'] = 'Groep';
$labels['cutyperesource'] = 'Middel';
$labels['cutyperoom'] = 'Room';
$labels['availfree'] = 'Vrij';
$labels['availbusy'] = 'Bezig';
$labels['availunknown'] = 'Onbekend';
$labels['availtentative'] = 'Misschien';
$labels['availoutofoffice'] = 'Niet Aanwezig';
$labels['scheduletime'] = 'Vind beschikbaarheid';
$labels['sendinvitations'] = 'Verzend uitnodigingen';
$labels['sendnotifications'] = 'Notify participants about modifications';
$labels['sendcancellation'] = 'Notify participants about event cancellation';
$labels['onlyworkinghours'] = 'Find availability within my working hours';
$labels['reqallattendees'] = 'Required/all participants';
$labels['prevslot'] = 'Previous Slot';
$labels['nextslot'] = 'Next Slot';
$labels['noslotfound'] = 'Unable to find a free time slot';
$labels['invitationsubject'] = 'U bent uitgenodigd voor "$title"';
$labels['invitationmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with all the event details which you can import to your calendar application.";
$labels['invitationattendlinks'] = "In case your email client doesn't support iTip requests you can use the following link to either accept or decline this invitation:\n\$url";
$labels['eventupdatesubject'] = '"$title" is bijgewerkt';
$labels['eventupdatesubjectempty'] = 'An event that concerns you has been updated';
$labels['eventupdatemailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nPlease find attached an iCalendar file with the updated event details which you can import to your calendar application.";
$labels['eventcancelsubject'] = '"$title" is geannuleerd';
$labels['eventcancelmailbody'] = "*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees\n\nThe event has been cancelled by \$organizer.\n\nPlease find attached an iCalendar file with the updated event details.";

// invitation handling
$labels['itipmailbodyaccepted'] = "\$sender has accepted the invitation to the following event:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipmailbodytentative'] = "\$sender has tentatively accepted the invitation to the following event:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipmailbodydeclined'] = "\$sender has declined the invitation to the following event:\n\n*\$title*\n\nWhen: \$date\n\nInvitees: \$attendees";
$labels['itipdeclineevent'] = 'Do you want to decline your invitation to this event?';
$labels['declinedeleteconfirm'] = 'Do you also want to delete this declined event from your calendar?';

$labels['notanattendee'] = 'You\'re not listed as an attendee of this event';
$labels['eventcancelled'] = 'Dit evenement is afgelast';
$labels['saveincalendar'] = 'bewaar in';

// event dialog tabs
$labels['tabsummary'] = 'Samenvatting';
$labels['tabrecurrence'] = 'Herhaling';
$labels['tabattendees'] = 'Deelnemers';
$labels['tabattachments'] = 'Toebehoren';
$labels['tabsharing'] = 'Delen';

// messages
$labels['deleteventconfirm'] = 'Do you really want to delete this event?';
$labels['deletecalendarconfirm'] = 'Do you really want to delete this calendar with all its events?';
$labels['deletecalendarconfirmrecursive'] = 'Do you really want to delete this calendar with all its events and sub-calendars?';
$labels['savingdata'] = 'Data wordt opgeslagen...';
$labels['errorsaving'] = 'Failed to save changes.';
$labels['operationfailed'] = 'The requested operation failed.';
$labels['invalideventdates'] = 'Invalid dates entered! Please check your input.';
$labels['invalidcalendarproperties'] = 'Invalid calendar properties! Please set a valid name.';
$labels['searchnoresults'] = 'No events found in the selected calendars.';
$labels['successremoval'] = 'The event has been deleted successfully.';
$labels['successrestore'] = 'The event has been restored successfully.';
$labels['errornotifying'] = 'Failed to send notifications to event participants';
$labels['errorimportingevent'] = 'Failed to import the event';
$labels['newerversionexists'] = 'A newer version of this event already exists! Aborted.';
$labels['nowritecalendarfound'] = 'No calendar found to save the event';
$labels['importedsuccessfully'] = 'The event was successfully added to \'$calendar\'';
$labels['attendeupdateesuccess'] = 'Successfully updated the participant\'s status';
$labels['itipsendsuccess'] = 'Invitation sent to participants.';
$labels['itipresponseerror'] = 'Failed to send the response to this event invitation';
$labels['itipinvalidrequest'] = 'This invitation is no longer valid';
$labels['sentresponseto'] = 'Successfully sent invitation response to $mailto';
$labels['localchangeswarning'] = 'You are about to make changes that will only be reflected on your calendar and not be sent to the organizer of the event.';
$labels['importsuccess'] = 'Successfully imported $nr events';
$labels['importnone'] = 'No events found to be imported';
$labels['importerror'] = 'An error occured while importing';
$labels['aclnorights'] = 'You do not have administrator rights on this calendar.';

// recurrence form
$labels['repeat'] = 'Herhaal';
$labels['frequency'] = 'Herhaal';
$labels['never'] = 'nooit';
$labels['daily'] = 'dagelijks';
$labels['weekly'] = 'weekelijks';
$labels['monthly'] = 'maandelijks';
$labels['yearly'] = 'jaarlijks';
$labels['rdate'] = 'on dates';
$labels['every'] = 'Elke';
$labels['days'] = 'dag(en)';
$labels['weeks'] = 'week / weken';
$labels['months'] = 'maand(en)';
$labels['years'] = 'year(s) in:';
$labels['bydays'] = 'Op';
$labels['untildate'] = 'de';
$labels['each'] = 'Elke';
$labels['onevery'] = 'Op elke';
$labels['onsamedate'] = 'Op dezelfde datum';
$labels['forever'] = 'oneindig';
$labels['recurrencend'] = 'tot';
$labels['forntimes'] = 'voor $nr keer';
$labels['first'] = 'eerste';
$labels['second'] = 'tweede';
$labels['third'] = 'derde';
$labels['fourth'] = 'vierde';
$labels['last'] = 'laatste';
$labels['dayofmonth'] = 'Dag van de maand';
$labels['addrdate'] = 'Add repeat date';

$labels['changeeventconfirm'] = 'Wijzig evenement';
$labels['removeeventconfirm'] = 'Verwijder evenement';
$labels['changerecurringeventwarning'] = 'This is a recurring event. Would you like to edit the current event only, this and all future occurences, all occurences or save it as a new event?';
$labels['removerecurringeventwarning'] = 'This is a recurring event. Would you like to remove the current event only, this and all future occurences or all occurences of this event?';
$labels['currentevent'] = 'Huidige';
$labels['futurevents'] = 'Toekomst';
$labels['allevents'] = 'Alle';
$labels['saveasnew'] = 'Bewaar als nieuw';

// birthdays calendar
$labels['birthdays'] = 'Birthdays';
$labels['birthdayscalendar'] = 'Birthdays Calendar';
$labels['displaybirthdayscalendar'] = 'Display birthdays calendar';
$labels['birthdayscalendarsources'] = 'From these address books';
$labels['birthdayeventtitle'] = '$name\'s Birthday';
$labels['birthdayage'] = 'Age $age';

?>
