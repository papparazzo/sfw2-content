<?php

/*
 *  Project:    sfw2-content
 *
 *  Copyright (C) 2019 Stefan Paproth <pappi-@gmx.de>
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
use SFW2\Routing\Result\Redirect;

use SFW2\Core\Database;
use SFW2\Authority\User;

use SFW2\Controllers\Controller\Helper\DateTimeHelperTrait;
use SFW2\Controllers\Controller\Helper\EMailHelperTrait;
use SFW2\Controllers\Controller\Helper\ImageHelperTrait;

use SFW2\Validator\Ruleset;
use SFW2\Validator\Validator;
use SFW2\Validator\Validators\IsUrl;

use Exception;

class Poster extends AbstractController {

    const FILE_NAME = 'poster.png';

    const FILE_TYPE_IMAGE = 0;
    const FILE_TYPE_PDF   = 1;

    const DIMENSIONS = 800;

    use DateTimeHelperTrait;
    use EMailHelperTrait;
    use ImageHelperTrait;

    protected Database $database;
    protected User $user;
    protected string $title;

    public function __construct(int $pathId, Database $database, User $user, string $title = '') {
        parent::__construct($pathId);
        $this->database = $database;
        $this->user = $user;
        $this->title = $title;
    }

    public function index(bool $all = false) : Content {
        unset($all);
        $content = new Content('SFW2\\Content\\Poster');

        $stmt =
            "SELECT `poster`.`Id`, `CreationDate`, `user`.`FirstName`, `user`.`LastName`, " .
            "`Email`, `FileName`, `Title`, `Link` " .
            "FROM `{TABLE_PREFIX}_poster` AS `poster` " .
            "LEFT JOIN `{TABLE_PREFIX}_user` AS `user` ON `user`.`Id` = `poster`.`UserId` " .
            "WHERE `poster`.`PathId` = '%s' " .
            "ORDER BY `Id` DESC ";

        $row = $this->database->selectRow($stmt, [$this->pathId]);
        if(empty($row)) {
            $content->assign('title',  $this->title);
            $content->assign('file',   '');
            $content->assign('link',   '');
        } else {
            $content->assign('date',   $this->getShortDate($row['CreationDate']));
            $content->assign('title',  $row['Title'] == '' ? $this->title : $row['Title']);
            $content->assign('file',   $row['FileName']);
            $content->assign('link',   $row['Link']);
            $content->assign('author', $this->getShortName($row));
            $content->assign('id',     $row['Id']);
        }
        return $content;
    }

    public function create() : Content {
        $content = new Content('Poster');

        $validateOnly = filter_input(INPUT_POST, 'validateOnly', FILTER_VALIDATE_BOOLEAN);

        $rulset = new Ruleset();
        $rulset->addNewRules('link', new IsUrl(IsUrl::WITH_HTTP_OR_HTTPS));

        $values = [];

        $validator = new Validator($rulset);
        $error = $validator->validate($_POST, $values);
        $content->assignArray($values);

        if(!$error) {
            $content->setError(true);
        }

        if($validateOnly || !$error) {
            return $content;
        }

        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $link  = $values['link']['value'];

        $this->delete();

        $fileName = $this->addFile();

        $stmt =
            "REPLACE INTO `{TABLE_PREFIX}_poster` " .
            "SET `CreationDate` = NOW(), " .
            "`PathId` = '%s', ".
            "`UserId` = '%s', " .
            "`Title` = '%s', " .
            "`Link` = '%s', " .
            "`FileName` = '%s' ";

        $this->database->insert(
            $stmt,
            [
                $this->pathId,
                $this->user->getUserId(),
                $title,
                $link,
                $fileName
            ]
        );
        return $content;
    }

    public function delete(bool $all = false) : Redirect {
        $file = 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR . self::FILE_NAME;
        if(is_file($file) && !unlink($file)) {
            throw new ResolverException("could not delete file <$file>", ResolverException::INVALID_DATA_GIVEN);
        }

        $entryId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if($entryId === false) {
            throw new ResolverException("invalid data given", ResolverException::INVALID_DATA_GIVEN);
        }
        $stmt = "DELETE FROM `{TABLE_PREFIX}_poster` WHERE `Id` = '%s' AND `PathId` = '%s'";

        if(!$all) {
            $stmt .= "AND `UserId` = '" . $this->database->escape($this->user->getUserId()) . "'";
        }
        $this->database->delete($stmt, [$entryId, $this->pathId]);
        return new Redirect();
    }

    public function read(bool $all = false) : Content {
        return new Content();
    }

    protected function addFile() : string {
        $folder = 'img' . DIRECTORY_SEPARATOR . $this->pathId . DIRECTORY_SEPARATOR;

        if(!isset($_POST['file'])) {
            throw new Exception("file not set");
        }

        if(!is_dir($folder) && !mkdir($folder, 0777, true)) {
            throw new Exception("could not create destination-folder <$folder>");
        }

        $chunk = explode(';', $_POST['file']);
        $type = explode(':', $chunk[0]);
        $type = $type[1];
        $data = explode(',', $chunk[1]);

        $filename = $folder . self::FILE_NAME;
        $orgFilename = $filename;

        switch($type) {
            case 'image/pjpeg':
            case 'image/jpeg':
            case 'image/jpg':
            case 'image/png':
            case 'image/x-png':
                $ftype = self::FILE_TYPE_IMAGE;
                break;

            case 'application/pdf':
                $ftype = self::FILE_TYPE_PDF;
                $orgFilename .= '.pdf';
                break;

            default:
                throw new Exception("invalid image type <$type> given!");
        }

        if(!file_put_contents($orgFilename, base64_decode($data[1]))) {
            throw new Exception("could not store file <" . self::FILE_NAME . "> in path <$folder>");
        }
        if($ftype == self::FILE_TYPE_PDF) {
            $this->convertPDFToJPG($orgFilename, $filename);
        }
        $this->generateThumb($filename, $filename, self::DIMENSIONS);

        return $filename;
    }
}
