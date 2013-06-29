mediawiki-FbnTraffic
====================

Mediawiki extension to view traffic accounting data from netacct-mysql (see http://netacct-mysql.gabrovo.com/) and FreeRADIUS database

installation
============

place in LocalSettings.php:

    /* database settings */
    $wgFbnTrafficDbIP['host'] = 'localhost';
    $wgFbnTrafficDbIP['user'] = 'mediawiki';
    $wgFbnTrafficDbIP['pw'] = '';
    $wgFbnTrafficDbIP['db'] = '';
    $wgFbnTrafficDbIP['tableUsers'] = 'Mitglieder';
    $wgFbnTrafficDbIP['tableTraffic'] = 'Traffic';
    $wgFbnTrafficDbIP['tableIP'] = 'IPs';
    $wgFbnTrafficDbRadius['host'] = 'localhost';
    $wgFbnTrafficDbRadius['user'] = 'mediawiki';
    $wgFbnTrafficDbRadius['pw'] = '';
    $wgFbnTrafficDbRadius['db'] = 'radius';
    $wgFbnTrafficDbRadius['tableAccounting'] = 'radacct';
    /* load extension */
    include_once("$IP/extensions/FbnTraffic/FbnTraffic.php");

TODO
====
- [ ] internationalization; use FbnTraffic.i18n.php and wfMessage()
- [ ] boost performance