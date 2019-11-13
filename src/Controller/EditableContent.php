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


class EditableContent extends AbstractController {

    use DateTimeHelperTrait;
    use EMailHelperTrait;

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var User
     */
    protected $user;

    protected $title;

    public function __construct(int $pathId, Database $database, User $user, string $title = null) {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->title = $title;
    }

    public function index($all = false) {
        unset($all);
        $content = new Content('SFW2\\Content\\EditableContent');
        $content->appendJSFile('EditableContent.handlebars.js');
        # Ask for permission
        $content->appendJSFile('EditableContentForm.handlebars.js');
        return $content;
    }

    public function read($all = false) {
        $content = new Content('EditableContent');

        $stmt =
            "SELECT `content`.`Id`, `CreationDate`, `user`.`FirstName`, `user`.`LastName`, `Email`, `Content`, `Title` " .
            "FROM `{TABLE_PREFIX}_content` AS `content` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `content`.`UserId` " .
            "WHERE `content`.`PathId` = '%s' " .
            "ORDER BY `Id` DESC ";

        $row = $this->database->selectRow($stmt, [$this->pathId]);
        if(empty($row)) {
            $entry['id'     ] = $this->createDummy();
            $entry['date'   ] = $this->getShortDate();
            $entry['title'  ] = $this->title;
            $entry['content'] = '';
            $entry['author' ] = '';
        } else {
            $entry = [];
            $entry['id'     ] = $row['Id'];
            $entry['date'   ] = $this->getShortDate($row['CreationDate']);
            $entry['title'  ] = $row['Title'] == '' ? $this->title : $row['Title'];
            $entry['content'] = $row['Content'];
            $entry['author' ] = $this->getShortName($row);
        }
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

    protected function modify($entryId = null, bool $all = false) {
        $content = new Content('EditableContent');

        /*
        $rulset = [
            'content' => ['doTrim', 'doConvertLineBreaks'],
            'title' => ['doTrim'],
        ];

        $values = [];

        Den Dataformatter gibt es nicht mehr!!!!
        $validator = new Data Form atter($rulset);
        $error = $validator->format($_POST, $values);
         *
         */
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

        #if(!$error) {
        #    $content->setError(true);
        #    return $content;
        #}

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

    protected function getShortName(array $data) {
        if(empty($data['Email'])) {
            return $data['FirstName'] . ' ' . $data['LastName'];
        }

        return (string)(new EMail($data['Email'], $data['FirstName'] . ' ' . $data['LastName']));
    }
}
