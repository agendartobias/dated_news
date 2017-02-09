<?php
namespace FalkRoeder\DatedNews\Controller;

use GeorgRinger\News\Utility\Cache;
use GeorgRinger\News\Utility\Page;
use \TYPO3\CMS\Core\Utility\GeneralUtility;
/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2017 Falk Röder <mail@falk-roeder.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * Class FalkRoeder\DatedNews\Controller\NewsController
 */
class NewsController extends \GeorgRinger\News\Controller\NewsController
{

    const SIGNAL_NEWS_CALENDAR_ACTION = 'calendarAction';
    const SIGNAL_NEWS_EVENTDETAIL_ACTION = 'eventDetailAction';
    const SIGNAL_NEWS_CREATEAPPLICATION_ACTION = 'createApplicationAction';
    const SIGNAL_NEWS_CONFIRMAPPLICATION_ACTION = 'confirmApplicationAction';

    /**
     * applicationRepository
     *
     * @var \FalkRoeder\DatedNews\Domain\Repository\ApplicationRepository
     * @inject
     */
    protected $applicationRepository = NULL;

    /**
     * Misc Functions
     *
     * @var \FalkRoeder\DatedNews\Utility\Div
     * @inject
     */
    protected $div;

    /**
     * Calendar view
     *
     * @param array $overwriteDemand
     * @return void
     */
    public function calendarAction(array $overwriteDemand = null)
    {
        $demand = $this->createDemandObjectFromSettings($this->settings);
        $demand->setActionAndClass(__METHOD__, __CLASS__);
        if ($this->settings['disableOverrideDemand'] != 1 && $overwriteDemand !== null) {
            $demand = $this->overwriteDemandObject($demand, $overwriteDemand);
        }
        $newsRecords = $this->newsRepository->findDemanded($demand);
        // Escaping quotes, doublequotes and backslashes for use in Javascript
        foreach ($newsRecords as $news) {
            $news->setTitle(addslashes($news->getTitle()));
            $news->setTeaser(addslashes($news->getTeaser()));
            $news->setDescription(addslashes($news->getDescription()));
            $news->setBodytext(addslashes($news->getBodytext()));
        }
    

        $assignedValues = array(
            'news' => $newsRecords,
            'overwriteDemand' => $overwriteDemand,
            'demand' => $demand,
        );

        $filterValues = [];
        
        if($this->settings['showCategoryFilter'] === '1'){
            $filterValues = array_merge($filterValues,$this->getCategoriesOfNews($newsRecords));

        }

        if($this->settings['showTagFilter'] === '1'){
            $filterValues = array_merge($filterValues,$this->getTagsOfNews($newsRecords));
        }
        if(!empty($filterValues)){
            $assignedValues['filterValues'] = $this->div->shuffle_assoc($filterValues);
        }

        $assignedValues = $this->emitActionSignal('NewsController', self::SIGNAL_NEWS_CALENDAR_ACTION, $assignedValues);
        $this->view->assignMultiple($assignedValues);
        Cache::addPageCacheTagsByDemandObject($demand);
    }

    

    /**
     * Single view of a news record
     *
     * @param \GeorgRinger\News\Domain\Model\News $news news item
     * @param int $currentPage current page for optional pagination
     * @return void
     */
    public function eventDetailAction(\GeorgRinger\News\Domain\Model\News $news = null, $currentPage = 1, \FalkRoeder\DatedNews\Domain\Model\Application $newApplication = null)
    {

        if (is_null($news)) {
            $previewNewsId = ((int)$this->settings['singleNews'] > 0) ? $this->settings['singleNews'] : 0;
            if ($this->request->hasArgument('news_preview')) {
                $previewNewsId = (int)$this->request->getArgument('news_preview');
            }

            if ($previewNewsId > 0) {
                if ($this->isPreviewOfHiddenRecordsEnabled()) {
                    $GLOBALS['TSFE']->showHiddenRecords = true;
                    $news = $this->newsRepository->findByUid($previewNewsId, false);
                } else {
                    $news = $this->newsRepository->findByUid($previewNewsId);
                }
            }
        }

        if (is_a($news,
                'GeorgRinger\\News\\Domain\\Model\\News') && $this->settings['detail']['checkPidOfNewsRecord']
        ) {
            $news = $this->checkPidOfNewsRecord($news);
        }

        if (is_null($news) && isset($this->settings['detail']['errorHandling'])) {
            $this->handleNoNewsFoundError($this->settings['detail']['errorHandling']);
        }

        $demand = $this->createDemandObjectFromSettings($this->settings);
        $demand->setActionAndClass(__METHOD__, __CLASS__);


        $applicationsCount = $this->applicationRepository->countReservedSlotsForNews($news->getUid());


        $news->setSlotsFree((int)$news->getSlots() - $applicationsCount);
        $slotoptions = array();
        $i = 1;
        while ($i <= $news->getSlotsFree()) {
            $slotoption = new \stdClass();
            $slotoption->key = $i;
            $slotoption->value = $i;
            $slotoptions[] = $slotoption;
            $i++;
        }

        
        $assignedValues = [
            'newsItem' => $news,
            'currentPage' => (int)$currentPage,
            'demand' => $demand,
            'newApplication' => $newApplication,
            'slotoptions' => $slotoptions,
            'formTimestamp' => time() // for form reload and doubled submit prevention
        ];

        $assignedValues = $this->emitActionSignal('NewsController', self::SIGNAL_NEWS_EVENTDETAIL_ACTION, $assignedValues);
        $this->view->assignMultiple($assignedValues);

        Page::setRegisterProperties($this->settings['detail']['registerProperties'], $news);
        if (!is_null($news) && is_a($news, 'GeorgRinger\\News\\Domain\\Model\\News')) {
            Cache::addCacheTagsByNewsRecords([$news]);
        }
    }

    /**
     * action createApplication
     *
     * @param \GeorgRinger\News\Domain\Model\News $news news item
     * @param \FalkRoeder\DatedNews\Domain\Model\Application $newApplication
     * @return void
     */
    public function createApplicationAction(\GeorgRinger\News\Domain\Model\News $news, \FalkRoeder\DatedNews\Domain\Model\Application $newApplication) {
//        \TYPO3\CMS\Extbase\Utility\DebuggerUtility::var_dump($this->request->getArguments(),'NewsController:140');

        if (is_null($news)) {
            $previewNewsId = ((int)$this->settings['singleNews'] > 0) ? $this->settings['singleNews'] : 0;
            if ($this->request->hasArgument('news_preview')) {
                $previewNewsId = (int)$this->request->getArgument('news_preview');
            }

            if ($previewNewsId > 0) {
                if ($this->isPreviewOfHiddenRecordsEnabled()) {
                    $GLOBALS['TSFE']->showHiddenRecords = true;
                    $news = $this->newsRepository->findByUid($previewNewsId, false);
                } else {
                    $news = $this->newsRepository->findByUid($previewNewsId);
                }
            }
        }

        if (is_a($news,
                'GeorgRinger\\News\\Domain\\Model\\News') && $this->settings['detail']['checkPidOfNewsRecord']
        ) {
            $news = $this->checkPidOfNewsRecord($news);
        }

        if (is_null($news) && isset($this->settings['detail']['errorHandling'])) {
            $this->handleNoNewsFoundError($this->settings['detail']['errorHandling']);
        }

        // prevents form submitted more than once
        $alwaysSubmitfortest = FALSE;
        if($alwaysSubmitfortest === TRUE || $this->applicationRepository->isFirstFormSubmission($newApplication->getFormTimestamp())){

            $newApplication->setPid($news->getPid());
            $newApplication->setHidden(TRUE);

            //set creationdate
            $date = new \DateTime();
            $newApplication->setCrdate($date->getTimestamp());

            //set total depending on either customer is an early bird or not and on earyBirdPrice is set
            if($news->getEarlyBirdPrice() != '' && $this->settings['earlyBirdDays'] != '' && $this->settings['earlyBirdDays'] != '0'){
                $earlybirdDate = clone $news->getEventstart();
                if($this->settings['earlyBirdDays'] != '') {
                    $earlybirdDate->setTime(0,0,0)->sub(new \DateInterval('P'.$this->settings['earlyBirdDays'].'D'));
                }

                $today = new \DateTime();
                $today->setTime(0,0,0);

                if($earlybirdDate >= $today) {
                    $newApplication->setCosts($newApplication->getReservedSlots() * floatval(str_replace(',','.',$news->getEarlyBirdPrice())));
                } else {
                    $newApplication->setCosts($newApplication->getReservedSlots() * floatval(str_replace(',','.',$news->getPrice())));
                }
            } else {
                $newApplication->setCosts($newApplication->getReservedSlots() * floatval(str_replace(',','.',$news->getPrice())));
            }
            

            $newApplication->addEvent($news);
            $this->applicationRepository->add($newApplication);


            $persistenceManager = GeneralUtility::makeInstance("TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager");
            $persistenceManager->persistAll();
            $newApplication->setTitle($news->getTitle()." - ".$newApplication->getName() . ' ' . $newApplication->getSurname() . '-' . $newApplication->getUid());
            $this->applicationRepository->update($newApplication);
            $persistenceManager->persistAll();

            // clearing cache of detailpage and currentpage because otherwise free slots are not updated in view
            // and the form is always sending the same if another booking is made
            $this->cacheService->clearPageCache($this->settings['detailPid'], intval($GLOBALS['TSFE']->id));

            $demand = $this->createDemandObjectFromSettings($this->settings);
            $demand->setActionAndClass(__METHOD__, __CLASS__);

            $assignedValues = [
                'newsItem' => $news,
                'currentPage' => (int)$currentPage,
                'demand' => $demand,
                'newApplication' => $newApplication
            ];

            $assignedValues = $this->emitActionSignal('NewsController', self::SIGNAL_NEWS_CREATEAPPLICATION_ACTION, $assignedValues);
            $this->view->assignMultiple($assignedValues);

            Page::setRegisterProperties($this->settings['detail']['registerProperties'], $news);
            if (!is_null($news) && is_a($news, 'GeorgRinger\\News\\Domain\\Model\\News')) {
                Cache::addCacheTagsByNewsRecords([$news]);
            }
            $this->sendMail($news, $newApplication, $this->settings);
        } else {
            $this->flashMessageService('applicationSendMessageAllreadySent','applicationSendMessageAllreadySentStatus','ERROR' );
        }
    }

    /**
     * action confirmApplication
     *
     * @param \FalkRoeder\DatedNews\Domain\Model\Application $newApplication
     * @return void
     */
    public function confirmApplicationAction(\FalkRoeder\DatedNews\Domain\Model\Application $newApplication)
    {

        $assignedValues = [];
        if(is_null($newApplication)){
            $this->flashMessageService('applicationNotFound','applicationNotFoundStatus','ERROR' );
        } else {
            //vor confirmation link validity check
            $date = new \DateTime();
            $hoursSinceBookingRequestSent = ($date->getTimestamp() - $newApplication->getCrdate()) / 3600;
            
            if($newApplication->isConfirmed() === TRUE){
                //was allready confirmed
                $this->flashMessageService('applicationAllreadyConfirmed','applicationAllreadyConfirmedStatus','INFO' );
            } else if($this->settings['dated_news']['validDaysConfirmationLink'] * 24 < $hoursSinceBookingRequestSent){
                //confirmation link not valid anymore
                $this->flashMessageService('applicationConfirmationLinkUnvalid','applicationConfirmationLinkUnvalidStatus','ERROR' );
            } else {
                //confirm
                $newApplication->setConfirmed(TRUE);
                $newApplication->setHidden(FALSE);
                $this->applicationRepository->update($newApplication);
                $persistenceManager = GeneralUtility::makeInstance("TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager");
                $persistenceManager->persistAll();
                $events = $newApplication->getEvents();
                $events->rewind();
                $this->sendMail($events->current(), $newApplication, $this->settings, TRUE);
                $assignedValues = [
                    'newApplication' => $newApplication,
                    'newsItem' => $events->current()
                ];
            }
        }

        // clearing cache of detailpage and currentpage because otherwise free slots are not updated in view
        // and the form is always sending the same if another booking is made
        $this->cacheService->clearPageCache($this->settings['detailPid'], intval($GLOBALS['TSFE']->id));
        
        $assignedValues = $this->emitActionSignal('NewsController', self::SIGNAL_NEWS_CONFIRMAPPLICATION_ACTION, $assignedValues);
        $this->view->assignMultiple($assignedValues);

    }

    /**
     * sendMail to applyer, admins and authors
     *
     * @param \GeorgRinger\News\Domain\Model\News $news news item
     * @param \FalkRoeder\DatedNews\Domain\Model\Application $newApplication
     * @return void
     */
    public function sendMail(\GeorgRinger\News\Domain\Model\News $news = NULL, \FalkRoeder\DatedNews\Domain\Model\Application $newApplication, $settings, $confirmation = FALSE){

        // from
        $sender = array();
        if (!empty($this->settings['senderMail'])) {
            $sender = (array($this->settings['senderMail'] => $this->settings['senderName']));
        }

        //validate Mailadress of applyer
        $applyerMail = $newApplication->getEmail();

        $applyer = array();
        if (is_string($applyerMail) && GeneralUtility::validEmail($applyerMail)) {
            $applyer = array($newApplication->getEmail() => $newApplication->getName() . ', ' . $newApplication->getSurname());
        } else {
            $this->flashMessageService('applicationSendMessageNoApplyerEmail','applicationSendMessageNoApplyerEmailStatus','ERROR' );
            $this->forward('eventDetail', NULL, NULL, array('news' => $news, 'currentPage' => 1, 'newApplication' => $newApplication));
        }

        //get filenames of flexform files to send to applyer
        if($confirmation === FALSE){
            $cObj = $this->configurationManager->getContentObject();
            $uid = $cObj->data['uid'];
            $fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
            $fileObjects = $fileRepository->findByRelation('tt_content', 'tx_datednews', $uid);
            $filenames = array();
            if(is_array($fileObjects)){
                foreach ($fileObjects as $file){
                    $filenames[] = $file->getOriginalFile()->getIdentifier();
                }
            }

            //FAL files does not work with gridelements, so add possibility to add file paths to TS. see https://forge.typo3.org/issues/71436
            $filesFormTS = explode(',', $this->settings['dated_news']['filesForMailToApplyer']);
            foreach ($filesFormTS as $fileName) {
                $filenames[] = trim($fileName);
            }

        } else {
            $filenames = array();
        }

        $recipientsCc = array();
        $recipientsBcc = array();

        // send email Customer
        $customerMailTemplate = $confirmation === TRUE ? 'MailConfirmationApplyer' : 'MailApplicationApplyer';
        if (!$this->div->sendEmail(
            $customerMailTemplate,
            $applyer,
            $recipientsCc,
            $recipientsBcc,
            $sender,
            \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('tx_datednews_domain_model_application.notificationemail_subject', 'dated_news', array('news' => $news->getTitle())),
            array('newApplication' => $newApplication, 'news' => $news, 'settings' => $settings),
            $filenames
        )) {
            $this->flashMessageService('applicationSendMessageApplyerError','applicationSendStatusApplyerErrorStatus','ERROR' );
        } else {
            if($confirmation === FALSE) {
                $this->flashMessageService('applicationSendMessage','applicationSendStatus','OK' );
            }
        }

        
        
        //Send to admins etc only when booking / application confirmed
        if($confirmation === TRUE){
            /** @var $to array Array to collect all the receipients */
            $to = array();

            //news Author
            if($this->settings['notificateAuthor']){
                $authorEmail = $news->getAuthorEmail();
                if (!empty($authorEmail)) {
                    $to [] = array('email' => $authorEmail,'name' => $news->getAuthor());
                }
            }

            //Plugins notification mail addresses
            if (!empty($this->settings['notificationMail'])) {
                $tsmails = explode(',',$this->settings['notificationMail']);
                foreach($tsmails as $tsmail) {
                    $to [] = array('email' => trim($tsmail),'name' => '');
                }
            }

            $recipients = array();
            if (is_array($to)) {
                foreach ($to as $pair) {
                    if (GeneralUtility::validEmail($pair['email'])) {
                        if (trim($pair['name'])) {
                            $recipients[$pair['email']] = $pair['name'];
                        } else {
                            $recipients[] = $pair['email'];
                        }
                    }
                }
            }

            if (!count($recipients)) {
                $this->flashMessageService('applicationSendMessageNoRecipients','applicationSendMessageNoRecipientsStatus','ERROR' );
                $this->forward('eventDetail', NULL, NULL, array('news' => $news, 'currentPage' => 1, 'newApplication' => $newApplication));
            }

            // send email to authors and Plugins mail addresses
            if ($this->div->sendEmail(
                'MailApplicationNotification',
                $recipients,
                $recipientsCc,
                $recipientsBcc,
                $applyer,
                \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('tx_datednews_domain_model_application.notificationemail_subject', 'dated_news', array('news' => $news->getTitle())),
                array('newApplication' => $newApplication, 'news' => $news, 'settings' => $settings),
                array()
            )) {
                $this->flashMessageService('applicationConfirmed','applicationConfirmedStatus','OK' );
            } else {
                $this->flashMessageService('applicationSendMessageGeneralError','applicationSendStatusGeneralErrorStatus','ERROR' );
            }
            
            if($this->settings['ics']){
                //create ICS File and send invitation
                $newsTitle = $news->getTitle();
                $icsLocation = '';
                $newsLocation = $news->getLocations();
                $i= 0;
                foreach ($newsLocation as $location){
                    $i++;
                    if($i ===1){
                        $icsLocation .= $location->getName();
                    } else {
                        $icsLocation .= ', ' . $location->getName();
                    }

                }
                $properties = array(
                    'dtstart' => $news->getEventstart()->getTimestamp(),
                    'dtend' => $news->getEventEnd()->getTimestamp(),
                    'location' => $icsLocation,
                    'summary' => $newsTitle,
                    'organizer' => $this->settings['senderMail'],
                    'attendee' => $applyerMail

                );
                //add description only if teaser has not html in it, whats the case if RTE is enabled
                if($news->getTeaser() == strip_tags($news->getTeaser())){
                    $properties['description'] = $news->getTeaser();
                }
                $ics = new \FalkRoeder\DatedNews\Services\ICS($properties);
                $icsAttachment = [
                    'content' => $ics->to_string(),
                    'name' => str_replace(' ','_',$newsTitle),

                ];
                $senderMail = substr_replace($this->settings['senderMail'],'noreply',0,strpos($this->settings['senderMail'], '@'));
                if (!$this->div->sendIcsInvitation(
                    'MailConfirmationApplyer',
                    $applyer,
                    $recipientsCc,
                    $recipientsBcc,
                    array($senderMail => $this->settings['senderName']),
                    \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('tx_datednews_domain_model_application.invitation_subject', 'dated_news', array('news' => $news->getTitle())),
                    array('newApplication' => $newApplication, 'news' => $news, 'settings' => $settings),
                    $filenames,
                    $icsAttachment
                )) {
                    $this->flashMessageService('applicationSendMessageApplyerError','applicationSendStatusApplyerErrorStatus','ERROR' );
                } else {
                    if($confirmation === FALSE) {
                        $this->flashMessageService('applicationSendMessage','applicationSendStatus','OK' );
                    }
                }
            }
            
            
        }
    }



    /**
     * @param \string $messageKey
     * @param \string $statusKey
     * @param \string $level
     *
     * @return void
     */
    function flashMessageService($messageKey, $statusKey, $level) {
        switch ($level) {
            case "NOTICE":
                $level = \TYPO3\CMS\Core\Messaging\AbstractMessage::NOTICE;
                break;
            case "INFO":
                $level = \TYPO3\CMS\Core\Messaging\AbstractMessage::INFO;
                break;
            case "OK":
                $level = \TYPO3\CMS\Core\Messaging\AbstractMessage::OK;
                break;
            case "WARNING":
                $level = \TYPO3\CMS\Core\Messaging\AbstractMessage::WARNING;
                break;
            case "ERROR":
                $level = \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR;
                break;
        }

        $this->addFlashMessage(
            \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate($messageKey, 'dated_news'),
            \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate($statusKey, 'dated_news'),
            $level,
            TRUE
        );
    }

    public function getCategoriesOfNews($newsRecords) {
        $newsCategories = [];
        foreach ($newsRecords as $news) {
            if($news->isShowincalendar() === TRUE) {
                $categories = $news->getCategories();
                foreach ($categories as $category) {
                    $title = $category->getTitle();
                    $bgColor = $category->getBackgroundcolor();
                    if(!array_key_exists($title, $newsCategories)){
                        $newsCategories[$title] =[];
                        $newsCategories[$title]['count'] = 1;
                        if (trim($bgColor) !== '') {
                            $newsCategories[$title]['color'] = $bgColor;
                        } 
                    } else {
                        $newsCategories[$title]['count'] = $newsCategories[$title]['count'] +1;
                    }
                }
            }
        }

        return $newsCategories;
    }

    public function getTagsOfNews($newsRecords) {
        $newsTags = [];
        foreach ($newsRecords as $news) {
            if($news->isShowincalendar() === TRUE) {
                $tags = $news->getTags();
                foreach ($tags as $tag) {
                    $title = $tag->getTitle();
                    if(!array_key_exists($title, $newsTags)){
                        $newsTags[$title] =[];
                        $newsTags[$title]['count'] = 1;
                    } else {
                        $newsTags[$title]['count'] = $newsTags[$title]['count'] +1;
                    }
                }
            }
        }

        return $newsTags;
    }

}