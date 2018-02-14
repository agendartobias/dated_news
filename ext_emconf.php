<?php

/***************************************************************
 * Extension Manager/Repository config file for ext: "dated_news"
 *
 * Auto generated by Extension Builder 2017-01-21
 *
 * Manual updates:
 * Only the data in the array - anything else is removed by next write.
 * "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = [
    'title'            => 'Dated News',
    'description'      => 'Extends the TYPO3 versatile news system extension tx_news with a calendar view using fullcalendar.js/qtip.js and allows to book for the now pricable events',
    'category'         => 'fe',
    'author'           => 'Falk Röder',
    'author_email'     => 'mail@falk-roeder.de',
    'state'            => 'stable',
    'internal'         => '',
    'uploadfolder'     => '0',
    'createDirs'       => '',
    'clearCacheOnLoad' => 1,
    'version'          => '5.1.1',
    'constraints'      => [
        'depends' => [
            'typo3'   => '7.6.13-8.7.99',
            'news'    => '5.3.0-6.3.99',
            'recurr'  => '1.0.0',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];
