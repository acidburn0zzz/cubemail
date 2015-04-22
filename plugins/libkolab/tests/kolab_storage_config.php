<?php

class kolab_storage_config_test extends PHPUnit_Framework_TestCase
{
    private $params_personal = array(
        'folder'     => 'Archive',
        'uid'        => '9',
        'message-id' => '<1225270@example.org>',
        'date'       => 'Mon, 20 Apr 2015 15:30:30 UTC',
        'subject'    => 'Archived',
    );
    private $url_personal = 'imap:///user/john.doe%40example.org/Archive/9?message-id=%3C1225270%40example.org%3E&date=Mon%2C+20+Apr+2015+15%3A30%3A30+UTC&subject=Archived';

    private $params_shared = array(
        'folder'     => 'Shared Folders/shared/Collected',
        'uid'        => '4',
        'message-id' => '<5270122@example.org>',
        'date'       => 'Mon, 20 Apr 2015 16:33:03 +0200',
        'subject'    => 'Catch me if you can',
    );
    private $url_shared = 'imap:///shared/Collected/4?message-id=%3C5270122%40example.org%3E&date=Mon%2C+20+Apr+2015+16%3A33%3A03+%2B0200&subject=Shared';

    private $params_other = array(
        'folder'     => 'Other Users/lucy.white/Mailings',
        'uid'        => '378',
        'message-id' => '<22448899@example.org>',
        'date'       => 'Tue, 14 Apr 2015 14:14:30 +0200',
        'subject'    => 'Happy Holidays',
    );
    private $url_other = 'imap:///user/lucy.white%40example.org/Mailings/378?message-id=%3C22448899%40example.org%3E&date=Tue%2C+14+Apr+2015+14%3A14%3A30+%2B0200&subject=Happy+Holidays';


    public static function setUpBeforeClass()
    {
        require_once __DIR__ . '/../../libkolab/libkolab.php';

        $rcube = rcube::get_instance();
        $rcube->user = null;

        $lib = new libkolab($rcube->plugins);
        $lib->init();

        // fake some session data to make storage work without an actual IMAP connection
        $_SESSION['username'] = 'john.doe@example.org';
        $_SESSION['imap_delimiter'] = '/';
        $_SESSION['imap_namespace'] = array(
            'personal' => array(array('','/')),
            'other'    => array(array('Other Users/','/')),
            'shared'   => array(array('Shared Folders/','/')),
            'prefix'   => '',
        );
    }

    function test_001_build_member_url()
    {
        $rcube = rcube::get_instance();
        $this->assertEquals('john.doe@example.org', $rcube->get_user_name());

        // personal namespace
        $url = kolab_storage_config::build_member_url($this->params_personal);
        $this->assertEquals('imap:///user/john.doe%40example.org/Archive/9?message-id=%3C1225270%40example.org%3E&date=Mon%2C+20+Apr+2015+15%3A30%3A30+UTC&subject=Archived', $url);

        // shared namespace
        $url = kolab_storage_config::build_member_url($this->params_shared);
        $this->assertEquals('imap:///shared/Collected/4?message-id=%3C5270122%40example.org%3E&date=Mon%2C+20+Apr+2015+16%3A33%3A03+%2B0200&subject=Catch+me+if+you+can', $url);

        // other users namespace
        $url = kolab_storage_config::build_member_url($this->params_other);
        $this->assertEquals('imap:///user/lucy.white%40example.org/Mailings/378?message-id=%3C22448899%40example.org%3E&date=Tue%2C+14+Apr+2015+14%3A14%3A30+%2B0200&subject=Happy+Holidays', $url);
    }

    function test_002_parse_member_url()
    {
        // personal namespace
        $params = kolab_storage_config::parse_member_url($this->url_personal);
        $this->assertEquals($this->params_personal['uid'], $params['uid']);
        $this->assertEquals($this->params_personal['folder'], $params['folder']);
        $this->assertEquals($this->params_personal['subject'], $params['params']['subject']);
        $this->assertEquals($this->params_personal['message-id'], $params['params']['message-id']);

        // shared namespace
        $params = kolab_storage_config::parse_member_url($this->url_shared);
        $this->assertEquals($this->params_shared['uid'], $params['uid']);
        $this->assertEquals($this->params_shared['folder'], $params['folder']);

        // other users namespace
        $params = kolab_storage_config::parse_member_url($this->url_other);
        $this->assertEquals($this->params_other['uid'], $params['uid']);
        $this->assertEquals($this->params_other['folder'], $params['folder']);
    }

    function test_003_build_parse_member_url()
    {
        // personal namespace
        $params = $this->params_personal;
        $params_ = kolab_storage_config::parse_member_url(kolab_storage_config::build_member_url($params));
        $this->assertEquals($params['uid'], $params_['uid']);
        $this->assertEquals($params['folder'], $params_['folder']);

        // shared namespace
        $params = $this->params_shared;
        $params_ = kolab_storage_config::parse_member_url(kolab_storage_config::build_member_url($params));
        $this->assertEquals($params['uid'], $params_['uid']);
        $this->assertEquals($params['folder'], $params_['folder']);

        // other users namespace
        $params = $this->params_other;
        $params_ = kolab_storage_config::parse_member_url(kolab_storage_config::build_member_url($params));
        $this->assertEquals($params['uid'], $params_['uid']);
        $this->assertEquals($params['folder'], $params_['folder']);
    }
}

    