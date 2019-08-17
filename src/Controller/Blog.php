<?php

/**
 *  SFW2 - SimpleFrameWork
 *
 *  Copyright (C) 2018  Stefan Paproth
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
use SFW2\Routing\Resolver\ResolverException;
use SFW2\Controllers\Controller\Helper\GetDivisionTrait;
use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Core\Database;
use SFW2\Core\DataValidator;

class Blog extends AbstractController {

    use GetDivisionTrait;
    use DateTimeHelperTrait;
    use ImageHelperTrait;
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

    public function index(bool $all = false) {
        unset($all);
        $content = new Content('SFW2\\Content\\Blog');
        $content->appendJSFile('Blog.handlebars.js');
        $content->appendJSFile('crud.js');
        $content->assign('divisions', $this->getDivisions());
        $content->assign('title', (string)$this->title);
        return $content;
    }

    public function read(bool $all = false) {
        $content = new Content('Blog');
        $entries = [];

        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : 5;

        $stmt =
            "SELECT `blog`.`Id`, `blog`.`CreationDate`, " .
            "`user`.`Email`, `blog`.`Content`, " .
            "`blog`.`Title`, `user`.`FirstName`, `user`.`LastName`, " .
            "`division`.`Name` AS `Resource`, " .
            "IF(`blog`.`UserId` = '%s', '1', '0') AS `OwnEntry` " .
            "FROM `{TABLE_PREFIX}_blog` AS `blog` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` " .
            "ON `user`.`Id` = `blog`.`UserId` " .
            "LEFT JOIN `{TABLE_PREFIX}_division` AS `division` " .
            "ON `division`.`Id` = `blog`.`DivisionId` " .
            "WHERE `PathId` = '%s' ";

        if($all) {
            $stmt .=
                "ORDER BY `blog`.`Id` DESC ".
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_blog', "WHERE `PathId` = '%s'", [$this->pathId]);
        } else {
            $stmt .=
                "AND `UserId` = '%s' " .
                "ORDER BY `blog`.`Id` DESC ".
                "LIMIT %s, %s ";
            $rows = $this->database->select($stmt, [$this->user->getUserId(), $this->pathId, $this->user->getUserId(), $start, $count]);
            $cnt = $this->database->selectCount('{TABLE_PREFIX}_blog', "WHERE `PathId` = '%s' AND `UserId` = '%s'", [$this->pathId, $this->user->getUserId()]);
        }

        foreach($rows as $row) {
            $cd = $this->getShortDate($row['CreationDate']);

            $entry = [];
            $entry['id'      ] = $row['Id'];
            $entry['date'    ] = $cd;
            $entry['title'   ] = $row['Title'];
            $entry['content' ] = $row['Content'];
            $entry['resname' ] = $row['Resource'];
            $entry['ownEntry'] = (bool)$row['OwnEntry'];
            $entry['image'   ] = $this->getImageFileName($row['FirstName'], $row['LastName']);
            $entry['mailaddr'] = $this->getEMail($row["Email"], $row['FirstName'] . ' ' . $row['LastName'], "Blogeintrag vom " . $cd);

            $entries[] = $entry;
        }

        $content->assign('offset', $start + $count);
        $content->assign('hasNext', $start + $count < $cnt);
        $content->assign('entries', $entries);
        return $content;
    }

    public function delete(bool $all = false) {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        $stmt = "DELETE FROM `{TABLE_PREFIX}_blog` WHERE `Id` = '%s' AND `PathId` = '%s'";

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
        return $this->modify($entryId);
    }

    public function create() {
        return $this->modify();
    }

    protected function modify($entryId = null, bool $all = false) {
        $content = new Content('Blog');

        $rulset = [
            'title' => ['isNotEmpty'],
            'content' => ['isNotEmpty'],
            'division' => ['isNotEmpty', 'isOneOf:' . implode(',', array_keys($this->getDivisions()))]
        ];

        $values = [];

        $validator = new DataValidator($rulset);
        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
            return $content;
        }

        if(is_null($entryId)) {
            $stmt =
                "INSERT INTO `{TABLE_PREFIX}_blog` " .
                "SET `CreationDate` = NOW(), " .
                "`Title` = '%s', " .
                "`Content` = '%s', " .
                "`UserId` = '%d', " .
                "`PathId` = '%d', " .
                "`DivisionId` = '%s' ";

            $id = $this->database->insert(
                $stmt,
                [
                    $values['title']['value'],
                    $values['content']['value'],
                    $this->user->getUserId(),
                    $this->pathId,
                    $values['division']['value']
                ]
            );
        } else {
            $stmt =
                "UPDATE `{TABLE_PREFIX}_blog` " .
                "SET `CreationDate` = NOW(), " .
                "`Title` = '%s', " .
                "`Content` = '%s', " .
                "`UserId` = '%d', " .
                "`DivisionId` = '%s' " .
                "WHERE `Id` = '%s' AND `PathId` = '%s'";

            if(!$all) {
                $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
            }

            $id = $this->database->update(
                $stmt,
                [
                    $values['title']['value'],
                    $values['content']['value'],
                    $this->user->getUserId(),
                    $values['division']['value'],
                    $entryId,
                    $this->pathId
                ]
            );
        }

        $cd = $this->getShortDate();
        $content->assign('resname',  ['value' => $this->getDivisionById($values['division']['value'])]);
        $content->assign('date',     ['value' => $cd]);
        $content->assign('id',       ['value' => $id]);
        $content->assign('image',    ['value' => $this->getImageFileNameByUser($this->user)]);
        $content->assign('mailaddr', ['value' => $this->getEMailByUser($this->user, "Blogeintrag vom " . $cd)]);
        $content->dataWereModified();
        return $content;
    }
}