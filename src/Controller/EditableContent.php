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

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SFW2\Core\HttpExceptions\HttpBadRequest;
use SFW2\Core\HttpExceptions\HttpNotFound;
use SFW2\Database\DatabaseInterface;
use SFW2\Routing\AbstractController;

use SFW2\Routing\HelperTraits\getRoutingDataTrait;
use SFW2\Routing\ResponseEngine;

use SFW2\Controllers\Widget\Obfuscator\EMail;
use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;

class EditableContent extends AbstractController {

    use getRoutingDataTrait;

    use DateTimeHelperTrait;
    use EMailHelperTrait;
    use ImageHelperTrait;

    protected Config $config;
    protected User $user;


    public function __construct(
        protected DatabaseInterface $database,
        protected bool $showEditor = true,
        protected bool $showModificationDate = true,
        protected string $title = ''
    )
    {
    }

    public function index(Request $request, ResponseEngine $responseEngine): Response
    {
        #$content->assign('showEditor', $this->showEditor);
        $content = [];

        return $responseEngine->render($request, $content, 'SFW2\\Content\\EditableContent');
    }

    public function read(Request $request, ResponseEngine $responseEngine): Response
    {
        $content = [];
        $stmt = /** @lang MySQL */
            "SELECT `content`.`Id`, `CreationDate`, `user`.`FirstName`, `user`.`LastName`, `Email`, `Content`, `Title` " .
            "FROM `{TABLE_PREFIX}_content` AS `content` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `content`.`UserId` " .
            "WHERE `content`.`PathId` = %s " .
            "ORDER BY `Id` DESC ";

        $row = $this->database->selectRow($stmt, [$this->pathId]);
        $entry = [];
        if(empty($row)) {
            $entry['id'      ] = $this->createDummy();
            $entry['date'    ] = $this->getShortDate();
            $entry['title'   ] = $this->title;
            $entry['content' ] = '';
            $entry['replaced'] = '';
            $entry['author'  ] = '';
        } else {
            $entry['id'      ] = $row['Id'];
            $entry['date'    ] = $this->getShortDate($row['CreationDate']);
            $entry['title'   ] = $row['Title'] == '' ? $this->title : $row['Title'];
            $entry['content' ] = $row['Content'];
            $entry['replaced'] = $this->parseContent($row['Content']);
            $entry['author'  ] = $this->getShortName($row);
        }
        $entry['showModificationDate'] = $this->showModificationDate;

        $entries = [];
        $entries[] = $entry;
        $content->assign('offset', 0);
        $content->assign('hasNext', false);
        $content->assign('entries', $entries);
        return $responseEngine->render($request, $content, 'SFW2\\Content\\EditableContent');
    }

    /**
     * @throws HttpNotFound
     */
    protected function createDummy(Request $request): int
    {
        $pathId = $this->getPathId($request);
        $stmt =
            "INSERT INTO `{TABLE_PREFIX}_content` SET " .
            "`PathId` = %s, " .
            "`CreationDate` = NOW(), " .
            "`UserId` = '%d', " .
            "`Title` = '', " .
            "`Content` = '' ";

        return $this->database->insert($stmt, [$pathId, $this->user->getUserId()]);
    }

    /**
     * @throws HttpBadRequest
     * @throws HttpNotFound
     */
    public function delete(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpBadRequest("invalid entry-id given");
        }
        $stmt = "DELETE FROM `{TABLE_PREFIX}_content` WHERE `Id` = %s AND `PathId` = %s";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }
        $pathId = $this->getPathId($request);
        if(!$this->database->delete($stmt, [$entryId, $pathId])) {
            throw new HttpNotFound("no entry found for id <$entryId>");
        }

        $folder = $this->getImageFolder();
        $files = glob($folder . '*');
        foreach($files as $file) {
            unlink($file);
        }
        return $responseEngine->render($request);
    }

    /**
     * @throws HttpBadRequest
     */
    public function update(Request $request, ResponseEngine $responseEngine): Response
    {
        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new HttpBadRequest("invalid entry-id given");
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

        $this->generateThumb($file, $file, 350);

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

    protected function modify($entryId = null, bool $all = false): Response
    {
        $content = new Content('EditableContent');

        $values = [
            'title' => [
                'value' => filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'hint' => ''
            ],
            'content' => [
                'value' => filter_input(INPUT_POST, 'content'),
                'hint' => ''
            ]
        ];

        $content->assignArray($values);

        if(is_null($entryId)) {
            $stmt =
                "INSERT INTO `{TABLE_PREFIX}_content` SET " .
                "`PathId` = %s, " .
                "`CreationDate` = NOW(), " .
                "`UserId` = '%d', " .
                "`Title` = %s, " .
                "`Content` = %s ";

            $entryId = $this->database->insert(
                $stmt,
                [$this->pathId, $this->user->getUserId(), $values['title']['value'], $values['content']['value']]
            );
        } else {
            $folder = $this->getImageFolder();
            $this->deleteAllUnneededImages($folder, $values['content']['value']);
            $stmt =
                "UPDATE `{TABLE_PREFIX}_content` SET " .
                "`CreationDate` = NOW(), " .
                "`UserId` = %d, " .
                "`Title` = %s, " .
                "`Content` = %s " .
                "WHERE `Id` = %s AND `PathId` = %s ";

            if(!$all) {
                $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
            }

            $data = [$this->user->getUserId(), $values['title']['value'], $values['content']['value'], $entryId, $this->pathId];
            $this->database->update($stmt, $data);
        }

        $content->assign('date',                 ['value' => $this->getShortDate()]);
        $content->assign('author',               ['value' => $this->getEMailByUser($this->user, $this->title)]);
        $content->assign('id',                   ['value' => $entryId]);
        $content->assign('showModificationDate', ['value' => $this->showModificationDate]);
        $content->assign('replaced',             ['value' => $this->parseContent($values['content']['value'])]);

        $content->dataWereModified();
        return $responseEngine->render($request, $content, 'SFW2\\Content\\EditableContent');
    }

    protected function parseContent(string $content) : string {
        $content = str_replace('{{chairman}}', $this->getChairman(), $content);
        $content = str_replace('{{webmaster}}', $this->getWebMasterEMailAddress(), $content);
        return $content;
    }

    protected function getWebMasterEMailAddress() : string {
        $email = $this->config->getVal('project', 'eMailWebMaster');
        return (string)(new EMail($email, $email));
    }

    protected function getChairman() : string {
        $stmt = /** @lang MySQL */
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
