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

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Authority\User;
use SFW2\Routing\Resolver\ResolverException;
use SFW2\Controllers\Controller\Helper\GetDivisionTrait;
use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;

use SFW2\Routing\ResponseEngine;
use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsNotEmpty;
use SFW2\Validator\Validators\IsOneOf;

class Blog extends AbstractController
{
    use getRoutingDataTrait;

    use GetDivisionTrait;
    use DateTimeHelperTrait;
    use ImageHelperTrait;
    use EMailHelperTrait;


    public function __construct(
        protected DatabaseInterface $database,
        protected string $title = ''
    ) {
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {

        $content = new Content('SFW2\\Content\\Blog');
        $content->appendJSFile('Blog.handlebars.js');
        $content->assign('divisions', $this->getDivisions());
        $content->assign('title', $this->title);
        $content->appendCSSFile('lightbox.min.css');
        $content->appendJSFile('lightbox.min.js');

        return $responseEngine->render($request, $content, 'SFW2\\Content\\Blog');
    }

    public function read(Request $request, ResponseEngine $responseEngine): Response
    {
        $pathId = $this->getPathId($request);
        $content = [];
        $entries = [];

        $count = (int)filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT);
        $start = (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);

        $count = $count ? $count : 5;

        $stmt = /** @lang MySQL */
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

    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }

        $stmtAdd = '';
        if(!$all) {
            $stmtAdd = "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }

        $stmt = "SELECT `blog`.`Content` FROM `{TABLE_PREFIX}_blog` AS `blog` WHERE `Id` = '%s' AND `PathId` = '%s' ";
        $ctnt = (string)$this->database->selectSingle($stmt . $stmtAdd, [$entryId, $this->pathId]);

        $folder = $this->getImageFolder();
        $this->deleteAllUnneededImages($folder, $ctnt, false);

        $stmt = "DELETE FROM `{TABLE_PREFIX}_blog` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$this->database->delete($stmt . $stmtAdd, [$entryId, $this->pathId])) {
            throw new ResolverException("no entry found", ResolverException::NO_PERMISSION);
        }
        return $responseEngine->render($request, [], 'SFW2\\Content\\Blog');
    }

    public function update(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        return $this->modify($entryId, $all);
    }

    public function create(Request $request, ResponseEngine $responseEngine): Response
    {
        return $this->modify();
    }

    public function addImage() : void {
        $folder = $this->getImageFolder();
        $file = $this->uploadImage($folder);

        $this->generateThumb($file, $file, 175);
        $data = [
            "url" => "/" . $file
        ];

        header('Content-type: application/json');
        echo json_encode($data);
        die();

       /*
        {
        "error": {
            "message": "The image upload failed because the image was too big (max 1.5MB)."
        }*/
    }

    protected function modify($entryId = null, bool $all = false) : Content {
        $content = new Content('Blog');

        $rulset = new Ruleset();
        $rulset->addNewRules('title', new IsNotEmpty());
        $rulset->addNewRules('content', new IsNotEmpty());
        $rulset->addNewRules('division', new IsNotEmpty(), new IsOneOf(array_keys($this->getDivisions())));

        $values = [];

        $validator = new Validator($rulset);
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
            $stmt = /** @lang MySQL */
                "UPDATE `{TABLE_PREFIX}_content_blog` " .
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