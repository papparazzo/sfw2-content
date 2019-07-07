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
use SFW2\Routing\PathMap\PathMap;

use SFW2\Controllers\Widget\Obfuscator\EMail;

use SFW2\Core\Database;
use SFW2\Core\Config;

class StaticContent extends AbstractController {

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var Config
     */
    protected $config;

    protected $template;

    protected $templateData;

    protected $title;

    public function __construct(
        int $pathId, PathMap $path, Database $database, Config $config, string $template, array $templateData = [], string $title = null
    ) {

        /*
        if($loginResetPathId != null) {
            $this->loginResetPath = $path->getPath($loginResetPathId);
        }
         */


        parent::__construct($pathId);
        $this->template = $template;
        $this->database = $database;
        $this->config = $config;
        $this->title = $title;
        $this->templateData = $templateData;
    }

    public function index($all = false) : Content {
        $email = $this->config->getVal('project', 'eMailWebMaster');
        $content = new Content($this->template);
        $content->assign('chairman', $this->getChairman());
        $content->assign('mailaddr', (string)(new EMail($email, $email)));
        $content->assign('title', $this->title);
        $content->assignArray($this->templateData);
        return $content;
    }

    protected function getChairman() {
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
