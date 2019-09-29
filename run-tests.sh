#!/bin/sh

PHPUNIT=`which phpunit`

if [ -f "vendor/phpunit/phpunit/phpunit" ]; then
    PHPUNIT="../vendor/phpunit/phpunit/phpunit"
fi

cd tests

$PHPUNIT ../plugins/libkolab/tests/kolab_date_recurrence.php
$PHPUNIT ../plugins/libkolab/tests/kolab_storage_config.php
$PHPUNIT ../plugins/libkolab/tests/kolab_storage_folder.php

$PHPUNIT ../plugins/libcalendaring/tests/libcalendaring.php
$PHPUNIT ../plugins/libcalendaring/tests/libvcalendar.php
