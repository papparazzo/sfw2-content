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
use SFW2\Routing\Result\Content;
use SFW2\Authority\User;
use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Core\DataValidator;
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
        $content->appendJSFile('content.handlebars.js');
        $content->appendJSFile('ckeditor/ckeditor.min.js');
        $content->appendJSFile('crud.js');
        $content->appendJSFile('contenteditable.js');
        $content->assign('title', $this->title);
        $this->setContent($content);
        return $content;
    }

    protected function setContent(Content $content) {
        $stmt =
            "SELECT `content`.`Id`, `CreationDate`, `user`.`FirstName`, `user`.`LastName`, `Email`, `Content` " .
            "FROM `{TABLE_PREFIX}_content` AS `content` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `content`.`UserId` " .
            "WHERE `content`.`PathId` = '%s' " .
            "ORDER BY `Id` DESC ";

        $row = $this->database->selectRow($stmt, [$this->pathId]);
        if(!empty($row) && !empty($row['Content'])) {
            $content->assign('content', $row['Content']);
            $content->assign('date',    $this->getShortDate($row['CreationDate']));
            $content->assign('author',  $this->getShortName($row));
        } else {
            $content->assign('content', '');
        }
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

    public function create() {
        $content = new Content('content');

        $rulset = [
            'content' => ['isNotEmpty'],
        ];

        $values = [];

        $validator = new DataValidator($rulset);
        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
            return $content;
        }

        $stmt = "INSERT INTO `{TABLE_PREFIX}_content` SET `PathId` = '%s', `CreationDate` = NOW(), `UserId` = %d, `Content` = '%s' ";

        $id = $this->database->insert(
            $stmt,
            [$this->pathId, $this->user->getUserId(), $values['content']['value']]
        );
        $content->assign('date',     ['value' => $this->getShortDate()]);
        $content->assign('author',   ['value' => $this->getEMailByUser($this->user, $this->title)]);
        $content->assign('id',       ['value' => $id]);
        return $content;
    }

    protected function getShortName(array $data) {
        if(empty($data['Email'])) {
            return $data['FirstName'] . ' ' . $data['LastName'];
        }

        return (string)(new EMail($data['Email'], $data['FirstName'] . ' ' . $data['LastName']));
    }

}
