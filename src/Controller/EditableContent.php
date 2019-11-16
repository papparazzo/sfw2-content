<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2017  Stefan Paproth
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/agpl.txt>.
 *
 */

namespace SFW2\Content\Controller;

use SFW2\Routing\AbstractController;
use SFW2\Routing\Resolver\ResolverException;
use SFW2\Routing\Result\Content;
use SFW2\Authority\User;

use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Core\Database;
use SFW2\Core\Config;

class EditableContent extends AbstractController {

    use DateTimeHelperTrait;
    use EMailHelperTrait;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var string
     */
    protected $title;

    /**
     * @var boolean
     */
    protected $showEditor;

    /**
     * @var boolean
     */
    protected $showModificationDate;

    public function __construct(int $pathId, Database $database, Config $config, User $user, bool $showEditor = true, bool $showModificationDate = true, string $title = '') {
        parent::__construct($pathId);
        $this->database = $database;
        $this->config = $config;
        $this->user = $user;
        $this->title = $title;
        $this->showEditor = $showEditor;
        $this->showModificationDate = $showModificationDate;
    }

    public function index($all = false) {
        unset($all);
        $content = new Content('SFW2\\Content\\EditableContent');
        $content->assign('showEditor', $this->showEditor);
        $content->appendJSFile('EditableContent.handlebars.js');
        # Ask for permission
        $content->appendJSFile('EditableContentForm.handlebars.js');
        return $content;
    }

    public function read($all = false) {
        unset($all);
        $content = new Content('EditableContent');

        $stmt =
            "SELECT `content`.`Id`, `CreationDate`, `user`.`FirstName`, `user`.`LastName`, `Email`, `Content`, `Title` " .
            "FROM `{TABLE_PREFIX}_content` AS `content` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `content`.`UserId` " .
            "WHERE `content`.`PathId` = '%s' " .
            "ORDER BY `Id` DESC ";

        $row = $this->database->selectRow($stmt, [$this->pathId]);
        $entry = [];
        if(empty($row)) {
            $entry['id'      ] = $this->createDummy();
            $entry['date'    ] = $this->getShortDate();
            $entry['title'   ] = $this->title;
            $entry['origin'  ] = '';
            $entry['replaced'] = '';
            $entry['author'  ] = '';
        } else {
            $entry['id'      ] = $row['Id'];
            $entry['date'    ] = $this->getShortDate($row['CreationDate']);
            $entry['title'   ] = $row['Title'] == '' ? $this->title : $row['Title'];
            $entry['origin'  ] = $row['Content'];
            $entry['replaced'] = $this->parseContent($row['Content']);
            $entry['author'  ] = $this->getShortName($row);
        }
        $entry['showModificationDate'] = $this->showModificationDate;

        $entries = [];
        $entries[] = $entry;
        $content->assign('offset', 0);
        $content->assign('hasNext', false);
        $content->assign('entries', $entries);
        return $content;
    }

    protected function createDummy() {
        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_content` SET " .
            "`PathId` = '%s', " .
            "`CreationDate` = NOW(), " .
            "`UserId` = '%d', " .
            "`Title` = '', " .
            "`Content` = '' ";

        return $this->database->insert($stmt, [$this->pathId, $this->user->getUserId()]);
    }

    public function delete($all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        $stmt = "DELETE FROM `{TABLE_PREFIX}_content` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }
        if(!$this->database->delete($stmt, [$entryId, $this->pathId])) {
            throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
        }
        return new Content();
    }

    public function update(bool $all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        return $this->modify($entryId, $all);
    }

    public function create() {
        return $this->modify();
    }

    protected function modify($entryId = null, bool $all = false) : Content {
        $content = new Content('EditableContent');

        $values = [
            'title' => [
                'value' => $_POST['title'],
                'hint' => ''
            ],
            'content' => [
                'value' => $_POST['content'],
                'hint' => ''
            ]
        ];

        $content->assignArray($values);

        if(is_null($entryId)) {
            $stmt =
                "INSERT INTO `{TABLE_PREFIX}_content` SET " .
                "`PathId` = '%s', " .
                "`CreationDate` = NOW(), " .
                "`UserId` = '%d', " .
                "`Title` = '%s', " .
                "`Content` = '%s' ";

            $entryId = $this->database->insert(
                $stmt,
                [$this->pathId, $this->user->getUserId(), $values['title']['value'], $values['content']['value']]
            );
        } else {
            $stmt =
                "UPDATE `{TABLE_PREFIX}_content` SET " .
                "`CreationDate` = NOW(), " .
                "`UserId` = '%d', " .
                "`Title` = '%s', " .
                "`Content` = '%s' " .
                "WHERE `Id` = '%s' AND `PathId` = '%s' ";

            if(!$all) {
                $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
            }

            $data = [$this->user->getUserId(), $values['title']['value'], $values['content']['value'], $entryId, $this->pathId];

            if($this->database->update($stmt, $data) == 0) {
                throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
            }
        }

        $content->assign('date',     ['value' => $this->getShortDate()]);
        $content->assign('author',   ['value' => $this->getEMailByUser($this->user, $this->title)]);
        $content->assign('id',       ['value' => $entryId]);
        $content->dataWereModified();
        return $content;
    }

    protected function getShortName(array $data) : string {
        if(empty($data['Email'])) {
            return $data['FirstName'] . ' ' . $data['LastName'];
        }

        return (string)(new EMail($data['Email'], $data['FirstName'] . ' ' . $data['LastName']));
    }

    protected function parseContent($content) : string {
        $content = str_replace('{{chairman}}', $this->getChairman(), $content);
        $content = str_replace('{{webmaster}}', $this->getWebMasterEMailAddress(), $content);
        return $content;
    }

    protected function getWebMasterEMailAddress() : string {
        $email = $this->config->getVal('project', 'eMailWebMaster');
        return (string)(new EMail($email, $email));
    }

    protected function getChairman() : string {
        $stmt =
            "SELECT CONCAT(IF(`user`.`Sex` = 'MALE', 'Herr ', 'Frau '), `user`.`FirstName`, ' ', `user`. `LastName`) AS `Chairman` " .
            "FROM `{TABLE_PREFIX}_position` AS `position` " .
            "LEFT JOIN `{TABLE_PREFIX}_division` AS `division` " .
            "ON `division`.`Id` = `position`.`DivisionId` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `position`.`UserId` " .
            "WHERE `position`.`Order` = '1' " .
            "AND `division`.`Position` = '0' ";

        return $this->database->selectSingle($stmt);
    }
}
